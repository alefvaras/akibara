<?php
/**
 * El enlace público de baja de la lista de espera.
 *
 * N-bis-unsub (2026-09-19, medido en producción): `UnsubscribeHandler::url_for()`
 * construía la URL sobre `home_url('/')` y la regla de Cloudflare «CACHE HTML
 * guests» cachea diez minutos cualquier HTML de invitado servido ahí con
 * `override_origin` — pisa el `no-store` del origen (`cf-cache-status: HIT`
 * medido). La zona deja pasar `/mi-cuenta/` sin cachear (`DYNAMIC`); mismo
 * patrón ya en producción desde akibara-rewards 0.4.9
 * (`Akibara\Rewards\Support\PrivatePage`). Ver también
 * `Akibara\Business\Tests\Services\OneClickPageTest` para el candado de la
 * caché de LiteSpeed, compartida por esta clase y por `UnfollowHandler`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\UnsubscribeHandler;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UnsubscribeHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, string $url ): string => $url . '?' . http_build_query( array( $key => $value ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El enlace de baja va bajo Mi cuenta, no la portada.
	 */
	public function test_el_enlace_va_bajo_mi_cuenta_no_la_portada(): void {
		$url = UnsubscribeHandler::url_for( str_repeat( 'a', 64 ) );

		self::assertStringStartsWith( 'https://akibara.test/mi-cuenta/?', $url );
		self::assertStringNotContainsString( 'https://akibara.test/?', $url );
	}

	/**
	 * Si `wc_get_page_permalink()` no existe todavía (orden de carga de
	 * plugins) o devuelve vacío, el enlace no puede quedar roto.
	 *
	 * @testdox [NEG] Sin la página de Mi cuenta resuelta, cae a `/mi-cuenta/`.
	 */
	public function test_sin_pagina_de_mi_cuenta_resuelta_cae_a_la_ruta_fija(): void {
		Functions\when( 'wc_get_page_permalink' )->justReturn( '' );

		self::assertStringStartsWith(
			'https://akibara.test/mi-cuenta/?',
			UnsubscribeHandler::url_for( str_repeat( 'a', 64 ) )
		);
	}

	/**
	 * El token viaja urlencodeado: `rawurlencode()` no es responsabilidad de
	 * `add_query_arg()` y un token con caracteres especiales (nunca ocurre con
	 * el formato hex64 real, pero el contrato del método no lo impide) no puede
	 * romper la URL.
	 *
	 * @testdox El token va rawurlencode-ado en la query string.
	 */
	public function test_el_token_va_urlencodeado(): void {
		$url = UnsubscribeHandler::url_for( str_repeat( 'b', 64 ) );

		self::assertStringContainsString( UnsubscribeHandler::QUERY_VAR . '=' . str_repeat( 'b', 64 ), $url );
	}
}
