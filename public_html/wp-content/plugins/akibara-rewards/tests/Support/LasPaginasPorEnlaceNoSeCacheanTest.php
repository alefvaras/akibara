<?php
/**
 * Las páginas por enlace (formulario de reseña, preferencias de correo) no se
 * guardan en ninguna caché.
 *
 * Se defendían sólo con `nocache_headers()`. En esta tienda eso no decide nada:
 * LiteSpeed resuelve con sus ajustes, y la regla de Cloudflare «CACHE HTML
 * guests» pisa el `no-store` del origen. Medido en producción el 2026-09-19:
 * `/resena/<token>/` respondía `cf-cache-status: HIT` junto a
 * `cache-control: no-store, private` (hallazgo S-87293fff).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Support\PrivatePage;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * @since 0.4.9
 */
final class LasPaginasPorEnlaceNoSeCacheanTest extends RewardsTestCase {

	/** @testdox Apagar la caché avisa a LiteSpeed por SU hook, no sólo por cabeceras. */
	public function test_no_cache_speaks_litespeeds_language(): void {
		Functions\when( 'nocache_headers' )->justReturn( null );
		Actions\expectDone( 'litespeed_control_set_nocache' )->once()->with( 'motivo de prueba' );

		PrivatePage::no_cache( 'motivo de prueba' );

		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'La constante que leen todos los plugins de caché.' );
	}

	/** @testdox La base de esas páginas es Mi cuenta, con red si WooCommerce no responde. */
	public function test_the_base_is_the_account_page(): void {
		self::assertSame( 'https://akibara.test/mi-cuenta/', PrivatePage::base_url() );

		Functions\when( 'wc_get_page_permalink' )->justReturn( '' );
		self::assertSame( 'https://akibara.test/mi-cuenta/', PrivatePage::base_url(), 'Sin página configurada cae a la ruta de fábrica, nunca a la portada.' );
	}

	/** @testdox El formulario de reseña se enlaza bajo Mi cuenta y no pierde el token. */
	public function test_the_review_form_url_is_out_of_the_edge_cache(): void {
		$url = ReviewForm::url_for( 'TkTkTkTkTkTkTkTkTkTkTkTk' );

		self::assertSame( 'https://akibara.test/mi-cuenta/?akb_review_token=TkTkTkTkTkTkTkTkTkTkTkTk', $url );
	}

	/**
	 * Los correos ya enviados llevan `/resena/<token>/` y sus tokens viven 30
	 * días: esa ruta no puede morir, y tampoco seguir sirviendo el formulario
	 * desde la caché del borde.
	 *
	 * @testdox La ruta antigua se reconoce para redirigirla, y la nueva no.
	 */
	public function test_the_old_route_is_recognised(): void {
		$es = new \ReflectionMethod( ReviewForm::class, 'es_ruta_antigua' );
		$es->setAccessible( true );

		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $parte = -1 ) => parse_url( (string) $url, $parte ) );

		$_SERVER['REQUEST_URI'] = '/resena/TkTkTkTkTkTkTkTkTkTkTkTk/';
		self::assertTrue( $es->invoke( null ) );

		$_SERVER['REQUEST_URI'] = '/mi-cuenta/?akb_review_token=TkTkTkTkTkTkTkTkTkTkTkTk';
		self::assertFalse( $es->invoke( null ), 'Redirigir la ruta nueva sería un bucle.' );

		unset( $_SERVER['REQUEST_URI'] );
	}

	/** @testdox Ninguna de las dos páginas se defiende ya sólo con cabeceras. */
	public function test_neither_page_relies_on_headers_alone(): void {
		foreach ( array( 'ReviewForm', 'InviteOptOut' ) as $clase ) {
			$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/' . $clase . '.php' );

			self::assertStringContainsString( 'PrivatePage::no_cache(', $fuente, $clase );
			self::assertStringContainsString( 'PrivatePage::base_url()', $fuente, $clase . ': la defensa contra el borde es de RUTA.' );
		}
	}
}
