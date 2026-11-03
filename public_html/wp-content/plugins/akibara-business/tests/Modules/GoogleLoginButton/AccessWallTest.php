<?php
/**
 * El muro de acceso: qué dice y a dónde devuelve.
 *
 * Dos defectos medidos en la auditoría de UX del 2026-09-05:
 *
 *   - `/mi-cuenta/favoritos/` mostraba «Accede a Akibara / Elige cómo continuar
 *     para comprar, seguir tus pedidos y gestionar tus preventas» sin nombrar
 *     los favoritos ni una vez (9,5 s de permanencia media);
 *   - quien tocaba el corazón de un tomo aterrizaba en el mismo cartel, sin
 *     saber qué se estaba guardando y sin camino de vuelta a esa ficha.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\GoogleLoginButton;

use Akibara\Business\Modules\GoogleLoginButton\AccessWall;
use Akibara\Business\Modules\GoogleLoginButton\GoogleLoginButton;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AccessWallTest extends TestCase {

	/** Como se sirve un tomo en PRODUCCIÓN: en la raíz, sin prefijo. */
	private const TOMO = 'https://akibara.cl/one-piece-1/';

	/** @var array<string,mixed> */
	private array $get_original = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_original                = $_GET;
		$GLOBALS['akibara_test_options']   = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '/' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->alias(
			static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' )
		);

		/*
		 * El catálogo de prueba modela PRODUCCIÓN, no el espejo.
		 *
		 * En producción los tomos se sirven en la RAÍZ y `url_to_postid()`
		 * devuelve 0 para ellos, aunque `woocommerce_permalinks.product_base`
		 * diga `/product`. En el espejo Docker sí llevan el prefijo, así que
		 * un doble que resolviera por `url_to_postid()` daría verde sobre un
		 * muro que en la tienda no nombra nada — que es exactamente lo que
		 * pasó el 2026-09-05, medido después de desplegar
		 * [[dobles-que-no-modelan-produccion]].
		 */
		Functions\when( 'url_to_postid' )->alias(
			static fn( string $url ): int => 0 === strpos( $url, 'https://akibara.cl/product/' ) ? 77 : 0
		);
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug, string $output = 'OBJECT', string $tipo = 'post' ) {
				if ( 'product' !== $tipo || 'one-piece-1' !== $slug ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->ID          = 77;
				$post->post_type   = 'product';
				$post->post_status = 'publish';

				return $post;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				if ( 77 !== $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->ID          = 77;
				$post->post_type   = 'product';
				$post->post_status = 'publish';

				return $post;
			}
		);
		Functions\when( 'get_the_title' )->justReturn( 'One Piece 1' );
		Functions\when( 'get_permalink' )->justReturn( self::TOMO );
	}

	protected function tearDown(): void {
		$_GET                            = $this->get_original;
		$GLOBALS['akibara_test_options'] = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El hallazgo tal cual: el cartel de favoritos no hablaba de favoritos.
	 *
	 * @testdox En Favoritos el muro nombra los favoritos, no el cartel genérico.
	 */
	public function test_the_favourites_wall_talks_about_favourites(): void {
		$copy = AccessWall::copy( 'favoritos', true );

		self::assertSame( AccessWall::DEFAULT_FAVORITOS_TITLE, $copy['title'] );
		self::assertSame( AccessWall::DEFAULT_FAVORITOS_SUB, $copy['sub'] );
		self::assertNotSame( AccessWall::DEFAULT_TITLE, $copy['title'] );
		self::assertStringContainsString( 'guardaste', mb_strtolower( $copy['title'] ) );
	}

	/**
	 * El H1 de la página ya dice «Favoritos», «Mi colección» o «Pedidos». Un H2
	 * que repita esa palabra ocupa la única línea que había para explicar qué
	 * gana el cliente al entrar — el mismo defecto que este muro vino a cerrar.
	 *
	 * @dataProvider titulares_y_su_h1
	 *
	 * @testdox El titular del muro añade información en vez de repetir el H1 de la página.
	 */
	public function test_the_heading_does_not_echo_the_page_h1( string $endpoint, string $h1 ): void {
		$titulo = mb_strtolower( AccessWall::copy( $endpoint, true )['title'] );

		foreach ( explode( ' ', mb_strtolower( $h1 ) ) as $palabra ) {
			if ( mb_strlen( $palabra ) < 5 ) {
				continue;
			}
			self::assertStringNotContainsString(
				$palabra,
				$titulo,
				sprintf( 'El H1 de /mi-cuenta/%s/ ya dice «%s».', $endpoint, $h1 )
			);
		}
	}

	/**
	 * Los H1 medidos en el espejo el 2026-09-05, sin sesión iniciada.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function titulares_y_su_h1(): array {
		return array(
			'favoritos' => array( 'favoritos', 'Favoritos' ),
			'coleccion' => array( 'coleccion', 'Mi colección' ),
			'pedidos'   => array( 'orders', 'Pedidos' ),
		);
	}

	/**
	 * @testdox Colección y Pedidos también hablan de lo suyo.
	 */
	public function test_each_endpoint_speaks_about_its_own_section(): void {
		$coleccion = AccessWall::copy( 'coleccion', true );
		$pedidos   = AccessWall::copy( 'orders', true );

		self::assertSame( AccessWall::DEFAULT_COLECCION_TITLE, $coleccion['title'] );
		self::assertSame( AccessWall::DEFAULT_ORDERS_TITLE, $pedidos['title'] );
		self::assertNotSame( $coleccion['sub'], $pedidos['sub'] );
	}

	/**
	 * Un endpoint sin texto propio NO puede heredar el de otro: mejor el cartel
	 * de siempre que uno que hable de otra cosa.
	 *
	 * @testdox Un endpoint desconocido cae al cartel general, con su variante sin registro.
	 */
	public function test_unknown_endpoints_fall_back_to_the_general_wall(): void {
		$con_registro = AccessWall::copy( 'lost-password', true );
		$sin_registro = AccessWall::copy( '', false );

		self::assertSame( AccessWall::DEFAULT_TITLE, $con_registro['title'] );
		self::assertSame( AccessWall::DEFAULT_SUB, $con_registro['sub'] );
		self::assertSame( AccessWall::DEFAULT_SUB_LOGIN, $sin_registro['sub'] );
	}

	/**
	 * @testdox Lo que el dueño escribe en Textos es lo que se lee en el muro.
	 */
	public function test_the_owner_text_is_what_the_customer_reads(): void {
		$GLOBALS['akibara_test_options'] = array(
			AccessWall::OPTION_FAVORITOS_TITLE => '  Tus <b>tomos</b> guardados  ',
		);

		$copy = AccessWall::copy( 'favoritos', true );

		self::assertSame( 'Tus tomos guardados', $copy['title'], 'Se sanea como copy de una línea.' );
		self::assertSame(
			AccessWall::DEFAULT_FAVORITOS_SUB,
			AccessWall::copy( 'favoritos', true )['sub'],
			'Lo que no tocó sigue en su literal.'
		);
	}

	/**
	 * @testdox Vaciar un texto repone el literal en vez de dejar el encabezado en blanco.
	 */
	public function test_a_blank_text_falls_back_instead_of_leaving_an_empty_heading(): void {
		$GLOBALS['akibara_test_options'] = array(
			AccessWall::OPTION_FAVORITOS_TITLE => '   ',
			AccessWall::OPTION_TITLE           => '',
		);

		self::assertSame( AccessWall::DEFAULT_FAVORITOS_TITLE, AccessWall::copy( 'favoritos', true )['title'] );
		self::assertSame( AccessWall::DEFAULT_TITLE, AccessWall::copy( '', true )['title'] );
	}

	/**
	 * El segundo hallazgo: el cliente tiene que saber qué estaba guardando y
	 * poder volver.
	 *
	 * @testdox Al venir de un tomo, el muro lo nombra y deja el enlace de vuelta.
	 */
	public function test_the_wall_names_the_product_the_customer_came_from(): void {
		$_GET['redirect_to'] = self::TOMO;

		$aviso = AccessWall::return_notice();

		self::assertStringContainsString( 'One Piece 1', $aviso );
		self::assertStringContainsString( 'href="' . self::TOMO . '"', $aviso );
		self::assertStringContainsString( 'Al entrar te llevamos de vuelta a', $aviso );
	}

	/**
	 * @testdox Sin redirect_to no se pinta ninguna línea de contexto.
	 */
	public function test_without_a_destination_there_is_no_context_line(): void {
		unset( $_GET['redirect_to'] );

		self::assertSame( '', AccessWall::return_notice() );
	}

	/**
	 * El `redirect_to` viaja en la URL y lo escribe cualquiera.
	 *
	 * Nombrar y devolver son dos permisos distintos: a una página cualquiera
	 * del sitio SÍ se devuelve —el cliente venía de ahí—, pero no hay tomo que
	 * nombrar; a un destino externo o administrativo no se hace ninguna de las
	 * dos cosas.
	 *
	 * @dataProvider destinos_que_no_se_nombran
	 *
	 * @testdox No se nombra nada que no sea un producto publicado de este sitio.
	 */
	public function test_only_a_published_product_of_this_site_is_named( string $destino, string $vuelta, string $porque ): void {
		$_GET['redirect_to'] = $destino;

		self::assertSame( '', AccessWall::return_notice(), $porque );
		self::assertSame( $vuelta, AccessWall::redirect_field_value( '' ), $porque );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function destinos_que_no_se_nombran(): array {
		return array(
			'otro sitio'     => array( 'https://otro-dominio.cl/producto/x/', '', 'Un destino externo convertiría el muro en un salto abierto.' ),
			'wp-admin codificado' => array( 'https://akibara.cl/wp%2Dadmin/plugins.php', '', 'El servidor resuelve %2D a un guion: la guarda no se puede saltar codificando un carácter.' ),
			'wp-admin doble'      => array( 'https://akibara.cl/wp%252Dadmin/plugins.php', '', 'Ni con dos pasadas de codificación.' ),
			'cerrar sesión'       => array( 'https://akibara.cl/wp-login.php?action=logout', '', 'El cliente entra y lo primero que haría la tienda es echarlo.' ),
			'salto de línea'      => array( "https://akibara.cl/contacto/\nhttps://evil.example/", '', 'Lo que se valida y lo que se emite tienen que ser lo mismo.' ),
			'tabulador'           => array( "https://akibara.cl/cont\tacto/", '', 'esc_url lo reescribiría a otra página distinta de la comprobada.' ),
			'byte nulo'           => array( "https://akibara.cl/contacto/\0.evil", '', 'Un carácter de control invalida el destino entero.' ),
			'wp-admin'       => array( 'https://akibara.cl/wp-admin/admin-ajax.php', '', 'Nunca se devuelve a un comprador al administrador.' ),
			'protocolo raro' => array( 'javascript:alert(1)', '', 'No empieza por el sitio.' ),
			'página normal'  => array( 'https://akibara.cl/contacto/', 'https://akibara.cl/contacto/', 'Se vuelve a ella, pero no hay tomo que nombrar.' ),
			'vacío'          => array( '', '', 'Sin destino no hay nada que decir.' ),
		);
	}

	/**
	 * Un borrador o un producto privado no existe para quien no ha entrado, y
	 * el `redirect_to` lo escribe cualquiera: nombrarlo sería filtrar su
	 * título.
	 *
	 * @testdox Un producto sin publicar no se nombra aunque el enlace apunte a él.
	 */
	public function test_an_unpublished_product_is_never_named(): void {
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				$post              = new \WP_Post();
				$post->ID          = $id;
				$post->post_type   = 'product';
				$post->post_status = 'draft';

				return $post;
			}
		);
		$_GET['redirect_to'] = self::TOMO;

		self::assertSame( '', AccessWall::return_notice() );
	}

	/**
	 * El candado del fallo que sólo se vio en la tienda.
	 *
	 * `url_to_postid()` resuelve el tomo cuando la URL lleva `/product/`
	 * delante —el espejo— y devuelve 0 cuando el tomo vive en la raíz —
	 * producción—. Con una sola vía, la línea de vuelta salía en Docker y no
	 * salía para el cliente.
	 *
	 * @testdox El tomo se nombra igual esté en la raíz o bajo /product/.
	 */
	public function test_the_product_resolves_with_or_without_the_permalink_prefix(): void {
		foreach (
			array(
				'raíz (producción)' => self::TOMO,
				'con prefijo'       => 'https://akibara.cl/product/one-piece-1/',
				'con categoría'     => 'https://akibara.cl/manga/shonen/one-piece-1/',
				'con parámetros'    => self::TOMO . '?utm_source=x',
			) as $forma => $url
		) {
			$_GET['redirect_to'] = $url;

			self::assertStringContainsString(
				'One Piece 1',
				AccessWall::return_notice(),
				'No nombra el tomo servido en la forma «' . $forma . '».'
			);
		}
	}

	/**
	 * La segunda vía busca por el último segmento de la ruta, así que hay que
	 * probar que no nombra cualquier cosa que comparta slug.
	 *
	 * @testdox El slug de una página no se cuela como si fuera un tomo.
	 */
	public function test_a_page_slug_is_not_mistaken_for_a_product(): void {
		$_GET['redirect_to'] = 'https://akibara.cl/preguntas-frecuentes/';

		self::assertSame( '', AccessWall::return_notice() );
	}

	/**
	 * El texto lo edita el dueño y un «%» suelto sería un fatal en la página de
	 * acceso, así que el hueco se rellena con `str_replace`, nunca con sprintf.
	 *
	 * @testdox Un porcentaje en el texto de vuelta no rompe la página.
	 */
	public function test_a_percent_sign_in_the_owner_text_is_harmless(): void {
		$GLOBALS['akibara_test_options'] = array(
			AccessWall::OPTION_RETURN => 'Vuelves a {producto} con tu 10% aplicado.',
		);
		$_GET['redirect_to'] = self::TOMO;

		$aviso = AccessWall::return_notice();

		self::assertStringContainsString( 'tu 10% aplicado', $aviso );
		self::assertStringContainsString( 'One Piece 1', $aviso );
	}

	/**
	 * @testdox Si el dueño borra el marcador, el tomo se añade al final en vez de desaparecer.
	 */
	public function test_the_product_survives_a_deleted_marker(): void {
		$GLOBALS['akibara_test_options'] = array(
			AccessWall::OPTION_RETURN => 'Te devolvemos donde estabas.',
		);
		$_GET['redirect_to'] = self::TOMO;

		$aviso = AccessWall::return_notice();

		self::assertStringContainsString( 'Te devolvemos donde estabas.', $aviso );
		self::assertStringContainsString( 'One Piece 1', $aviso );
	}

	/**
	 * La promesa de vuelta sólo es cierta si el formulario la lleva: WooCommerce
	 * honra `$_POST['redirect']`, y sin ese campo cae al `Referer`, que en un
	 * envío desde el muro es el muro mismo.
	 *
	 * @testdox El destino que se hornea en el formulario es el tomo del que vino el cliente.
	 */
	public function test_the_form_carries_the_destination_back(): void {
		$_GET['redirect_to'] = self::TOMO;

		self::assertSame( self::TOMO, AccessWall::redirect_field_value( '' ) );
		self::assertSame( self::TOMO, AccessWall::redirect_field_value( 'favoritos' ), 'El tomo pesa más que el endpoint.' );
	}

	/**
	 * El botón de Google es la acción PRIMARIA del muro y era el único de los
	 * tres caminos que no cumplía la promesa: su `state` de OAuth llevaba el
	 * muro. Medido en producción el 2026-09-05 decodificando ese `state`.
	 *
	 * @testdox Google vuelve al mismo sitio que los dos formularios, no al muro.
	 */
	public function test_google_returns_to_the_same_place_as_the_two_forms(): void {
		$_GET['redirect_to'] = self::TOMO;
		$modulo              = new GoogleLoginButton();

		$destino = $modulo->google_returns_where_the_customer_came_from( 'https://akibara.cl/mi-cuenta/?redirect_to=lo-que-sea' );

		self::assertSame( self::TOMO, $destino, 'El tercer camino tiene que leer el MISMO valor que los otros dos.' );
		self::assertSame(
			AccessWall::redirect_field_value( '' ),
			$destino,
			'Una sola fuente para los tres: si divergen, la promesa se cumple o no según el botón que se pulse.'
		);
	}

	/**
	 * @testdox Sin destino propio, Google se queda con el que traía.
	 */
	public function test_google_keeps_its_own_default_when_there_is_nothing_better(): void {
		unset( $_GET['redirect_to'] );
		$modulo = new GoogleLoginButton();

		self::assertSame(
			'https://akibara.cl/mi-cuenta/',
			$modulo->google_returns_where_the_customer_came_from( 'https://akibara.cl/mi-cuenta/' ),
			'Sin nada que aportar, no se le pisa el destino al tercero.'
		);
	}

	/**
	 * @testdox Sin redirect_to, quien entra por Favoritos vuelve a Favoritos.
	 */
	public function test_the_endpoint_is_the_destination_when_nothing_else_was_asked(): void {
		unset( $_GET['redirect_to'] );
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn( string $endpoint ): string => 'https://akibara.cl/mi-cuenta/' . $endpoint . '/'
		);

		self::assertSame( 'https://akibara.cl/mi-cuenta/favoritos/', AccessWall::redirect_field_value( 'favoritos' ) );
		self::assertSame( '', AccessWall::redirect_field_value( '' ), 'El panel general es a donde WooCommerce va por su cuenta.' );
	}

	/**
	 * @testdox Si el cliente está en el checkout o el destino pedido es el checkout, Google preserva el checkout.
	 */
	public function test_google_preserves_checkout_destination(): void {
		$checkout_url = 'https://akibara.cl/finalizar-compra/';
		Functions\when( 'wc_get_checkout_url' )->justReturn( $checkout_url );
		Functions\when( 'is_checkout' )->justReturn( true );

		$modulo = new GoogleLoginButton();

		self::assertSame(
			$checkout_url,
			$modulo->google_returns_where_the_customer_came_from( 'https://akibara.cl/mi-cuenta/' ),
			'Estando en checkout, Google debe devolver siempre a finalizar-compra.'
		);

		// Con is_checkout false pero el default ya siendo checkout
		Functions\when( 'is_checkout' )->justReturn( false );
		self::assertSame(
			$checkout_url,
			$modulo->google_returns_where_the_customer_came_from( $checkout_url )
		);

		// Con redirect_to apuntando a checkout
		$_GET['redirect_to'] = $checkout_url;
		self::assertSame(
			$checkout_url,
			$modulo->google_returns_where_the_customer_came_from( 'https://akibara.cl/mi-cuenta/' )
		);
		unset( $_GET['redirect_to'] );
	}

	/**
	 * @testdox product_id_for resuelve rutas de un solo segmento vía get_page_by_path sin llamar a url_to_postid.
	 */
	public function test_product_id_for_resolves_single_segment_path_first(): void {
		// Mock url_to_postid para lanzar excepción si se llamara en un slug de un segmento
		Functions\when( 'url_to_postid' )->alias(
			static function ( string $url ): int {
				if ( 0 === strpos( $url, 'https://akibara.cl/one-piece-1/' ) ) {
					self::fail( 'url_to_postid no debería llamarse para un slug raíz de un solo segmento.' );
				}
				return 0;
			}
		);

		$_GET['redirect_to'] = self::TOMO;
		$notice = AccessWall::return_notice();

		self::assertStringContainsString( 'One Piece 1', $notice );
		self::assertStringContainsString( self::TOMO, $notice );
		unset( $_GET['redirect_to'] );
	}

	/**
	 * @testdox on_google_user_created marca _wc_email_verified y dispara woocommerce_customer_email_verified.
	 */
	public function test_google_user_created_verifies_email(): void {
		$user_id   = 42;
		$user_data = (object) array(
			'given_name'  => 'Luffy',
			'family_name' => 'Monkey',
			'email'       => 'luffy@akibara.cl',
		);

		$wp_user             = new \WP_User();
		$wp_user->ID         = $user_id;
		$wp_user->user_email = 'luffy@akibara.cl';

		Functions\when( 'get_user_by' )->alias(
			static fn( string $field, $val ) => 42 === (int) $val ? $wp_user : null
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $val ) use ( $user_id ) {
				self::assertSame( $user_id, $uid );
				self::assertSame( '_wc_email_verified', $key );
				self::assertSame( 'luffy@akibara.cl', $val );
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();

		$action_fired = false;
		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$action_fired, $user_id ) {
				if ( 'woocommerce_customer_email_verified' === $hook ) {
					self::assertSame( $user_id, $args[0] );
					$action_fired = true;
				}
			}
		);

		$modulo = new GoogleLoginButton();
		$modulo->on_google_user_created( $user_id, $user_data );

		self::assertTrue( $action_fired, 'woocommerce_customer_email_verified debe dispararse para que WooCommerce vincule los pedidos.' );
	}
}
