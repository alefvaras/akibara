<?php
/**
 * Pruebas del destino de vuelta tras iniciar sesión.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\NavigationLinks;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NavigationLinksReturnTest extends TestCase {

	private const CUENTA = 'https://akibara.cl/mi-cuenta/';

	/** @var array<string,mixed> */
	private array $server_original = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->server_original = $_SERVER;

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '/' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( self::CUENTA );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '?' . $key . '=' . $value
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_original;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El prefijo del sitio NO alcanza como guard.
	 *
	 * `home_url( '/wp-admin/admin-ajax.php' )` lo cumple, y ese era justamente
	 * el destino que producía un llamador que resolvía la URL actual desde
	 * `REQUEST_URI` DENTRO de un handler AJAX: el invitado que pulsaba «Inicia
	 * sesión» en la landing de serie aterrizaba en el endpoint de AJAX.
	 *
	 * @dataProvider destinos
	 *
	 * @testdox Should refuse admin destinations and keep only public site pages.
	 */
	public function test_solo_acepta_destinos_publicos_del_sitio( string $destino, string $esperado, string $porque ): void {
		self::assertSame( $esperado, NavigationLinks::account_url_returning_to( $destino ), $porque );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function destinos(): array {
		$con = static fn( string $url ): string => self::CUENTA . '?redirect_to=' . rawurlencode( $url );

		return array(
			'landing de serie'   => array(
				'https://akibara.cl/serie/one-piece/',
				$con( 'https://akibara.cl/serie/one-piece/' ),
				'El caso normal: se vuelve donde estaba.',
			),
			'endpoint de AJAX'   => array(
				'https://akibara.cl/wp-admin/admin-ajax.php',
				self::CUENTA,
				'Un endpoint administrativo nunca es el sitio al que devolver a un comprador.',
			),
			'panel de admin'     => array(
				'https://akibara.cl/wp-admin/edit.php?post_type=product',
				self::CUENTA,
				'Tampoco una pantalla del panel.',
			),
			'sitio ajeno'        => array(
				'https://otro-sitio.cl/promo/',
				self::CUENTA,
				'`redirect_to` externo sería un salto abierto.',
			),
			'destino vacío'      => array( '', self::CUENTA, 'Sin destino, la cuenta a secas.' ),
		);
	}

	/**
	 * El helper compartido descarta la cadena de consulta: puede traer facetas,
	 * `utm_*` o el `redirect_to` de un rebote anterior.
	 *
	 * @testdox Should return to the current path without its query string.
	 */
	public function test_vuelve_a_la_ruta_actual_sin_la_cadena_de_consulta(): void {
		$_SERVER['REQUEST_URI'] = '/serie/one-piece/?_disponibilidad=instock&utm_source=ig';

		self::assertSame(
			self::CUENTA . '?redirect_to=' . rawurlencode( 'https://akibara.cl/serie/one-piece/' ),
			NavigationLinks::account_url_returning_here()
		);
	}

	/**
	 * En un handler AJAX la petición en curso ES `admin-ajax.php`, así que el
	 * helper no puede inventar un destino: devuelve la cuenta a secas y el
	 * enlace conserva el que resolvió el render de la página.
	 *
	 * @testdox Should fall back to the plain account URL during an AJAX request.
	 */
	public function test_durante_un_ajax_no_promete_un_destino_falso(): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';

		self::assertSame( self::CUENTA, NavigationLinks::account_url_returning_here() );
	}
}
