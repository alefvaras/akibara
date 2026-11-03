<?php
/**
 * La baja de un clic.
 *
 * Dos invariantes que no son opcionales: la firma tiene que impedir dar de baja
 * a otro cliente, y el GET NO puede mutar — los link-scanners corporativos y el
 * prefetch del inbox hacen GET a todos los enlaces de un correo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\UnfollowHandler;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UnfollowHandlerTest extends TestCase {

	/** @var array<int, array<int, int>> user_id ⇒ term_ids dados de baja. */
	private array $bajas = array();

	/** @var array<int, string> user_id ⇒ '1' si baja global. */
	private array $bajas_globales = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bajas          = array();
		$this->bajas_globales = array();

		$escape = static fn ( $value ): string => htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( (string) $value ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt-de-prueba' );
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		// La página «Listo» enlaza a la Colección. Se declaran explícitas: otro
		// test de la suite las define y `function_exists()` respondería por
		// contagio con una función sin comportamiento.
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn ( string $slug, string $value = '', string $permalink = '' ): string =>
				( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : 'https://akibara.test/mi-cuenta/' ) . $slug . '/'
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		// `headers_sent()` es interna de PHP y Patchwork no la redefine sin
		// declararla en `patchwork.json`; no hace falta: bajo CLI `header()` es
		// un no-op silencioso, así que la página se renderiza igual.
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
		// El `exit` de la página se corta con el mismo filtro que usa la baja de
		// la lista de espera; sin eso el test moriría con el proceso.
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => 'akb_sf_unfollow_should_exit' === $hook ? false : $value
		);

		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) {
				if ( FollowRegistry::META_OPTOUT === $key ) {
					return $this->bajas[ $user_id ] ?? array();
				}

				return $this->bajas_globales[ $user_id ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				if ( FollowRegistry::META_OPTOUT === $key ) {
					$this->bajas[ $user_id ] = (array) $value;
				}
				if ( FollowRegistry::META_OPTOUT_ALL === $key ) {
					$this->bajas_globales[ $user_id ] = (string) $value;
				}

				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		// Se VACÍA, no se destruye: `unset($_GET)` borra la superglobal para el
		// resto del proceso y cualquier test posterior que la lea muere con
		// «Undefined global variable» — verde o rojo según el orden.
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pedir( string $metodo, int $user_id, int $term_id, ?string $token = null ): string {
		$_SERVER['REQUEST_METHOD'] = $metodo;
		$_GET                      = array(
			UnfollowHandler::QUERY_USER => (string) $user_id,
			UnfollowHandler::QUERY_TERM => (string) $term_id,
			UnfollowHandler::QUERY_KEY  => $token ?? UnfollowHandler::token( $user_id, $term_id ),
		);

		ob_start();
		( new UnfollowHandler() )->handle();

		return (string) ob_get_clean();
	}

	/** @testdox Un token válido da de baja SÓLO esa serie. */
	public function test_token_valido_da_de_baja_solo_esa_serie(): void {
		$this->bajas[10] = array( 99 );

		$html = $this->pedir( 'POST', 10, 42 );

		self::assertSame( array( 42, 99 ), $this->bajas[10], 'La baja se añade sin perder las anteriores.' );
		self::assertStringContainsString( 'Listo', $html );
	}

	/** @testdox La baja global usa term_id 0 y no toca las bajas por serie. */
	public function test_la_baja_global_usa_el_termino_cero(): void {
		$this->pedir( 'POST', 10, 0 );

		self::assertSame( '1', $this->bajas_globales[10] );
		self::assertArrayNotHasKey( 10, $this->bajas );
	}

	/**
	 * Sin `hash_equals` sobre una firma que incluye el `user_id`, cambiar ese id
	 * en la URL daría de baja a otro cliente.
	 *
	 * @testdox [NEG] Un token manipulado no da de baja a nadie.
	 */
	public function test_token_manipulado_no_da_de_baja_a_nadie(): void {
		// El token del cliente 10 no sirve para el 11.
		$robado = UnfollowHandler::token( 10, 42 );

		$html = $this->pedir( 'POST', 11, 42, $robado );

		self::assertSame( array(), $this->bajas );
		self::assertStringContainsString(
			'Listo',
			$html,
			'La respuesta es la misma: no puede confirmar ni negar que un cliente existe.'
		);
	}

	/** @testdox [NEG] Cambiar la serie del token tampoco funciona. */
	public function test_cambiar_la_serie_del_token_no_funciona(): void {
		$this->pedir( 'POST', 10, 43, UnfollowHandler::token( 10, 42 ) );

		self::assertSame( array(), $this->bajas );
	}

	/**
	 * Los link-scanners corporativos y el prefetch del inbox hacen GET a todos
	 * los enlaces: si el GET mutara, escanear el correo daría de baja sin
	 * consentimiento. Es también lo que exige el RFC 8058.
	 *
	 * @testdox [NEG] Un GET no muta nada: sólo confirma.
	 */
	public function test_un_get_no_muta_nada(): void {
		$html = $this->pedir( 'GET', 10, 42 );

		self::assertSame( array(), $this->bajas );
		self::assertStringContainsString( '<form method="post"', $html );
		self::assertStringContainsString( 'Confirmar baja', $html );
	}

	/** @testdox El formulario de confirmación reenvía la MISMA firma. */
	public function test_el_formulario_reenvia_la_misma_firma(): void {
		$html = $this->pedir( 'GET', 10, 42 );

		self::assertStringContainsString( UnfollowHandler::token( 10, 42 ), $html );
	}

	/** @testdox La confirmación de una serie dice que las demás siguen igual. */
	public function test_la_confirmacion_por_serie_aclara_que_las_otras_siguen(): void {
		self::assertStringContainsString( 'Las demás siguen igual', $this->pedir( 'GET', 10, 42 ) );
		self::assertStringContainsString( 'cualquiera de tus series', $this->pedir( 'GET', 10, 0 ) );
	}

	/** @testdox Una petición sin los parámetros de baja sigue su curso. */
	public function test_una_peticion_normal_no_se_intercepta(): void {
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		( new UnfollowHandler() )->handle();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	/** @testdox [NEG] Un parámetro array no revienta ni da de baja. */
	public function test_un_parametro_array_no_revienta(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = array(
			UnfollowHandler::QUERY_USER => array( '10' ),
			UnfollowHandler::QUERY_TERM => '42',
			UnfollowHandler::QUERY_KEY  => UnfollowHandler::token( 10, 42 ),
		);

		ob_start();
		( new UnfollowHandler() )->handle();
		$html = (string) ob_get_clean();

		self::assertSame( array(), $this->bajas );
		self::assertStringContainsString( 'Listo', $html );
	}

	/**
	 * La página «Listo» cierra el ciclo: dice cómo volver. Y con token inválido
	 * sirve la misma página —con el mismo enlace— sin mutar nada.
	 *
	 * @testdox La página «Listo» enlaza al interruptor de Mi cuenta → Colección.
	 */
	public function test_la_pagina_listo_enlaza_a_la_coleccion(): void {
		$html = $this->pedir( 'POST', 10, 0 );

		self::assertStringContainsString( 'Si cambias de idea, los vuelves a encender en', $html );
		self::assertStringContainsString( '<a href="https://akibara.test/mi-cuenta/coleccion/">Mi cuenta, pestaña Colección</a>', $html );

		$robado = $this->pedir( 'POST', 11, 0, UnfollowHandler::token( 10, 0 ) );

		self::assertStringContainsString( 'https://akibara.test/mi-cuenta/coleccion/', $robado );
		self::assertArrayNotHasKey( 11, $this->bajas_globales, 'Token de otro cliente: misma página, sin mutar.' );
	}

	/** @testdox La baja es idempotente: repetirla no duplica el término. */
	public function test_la_baja_es_idempotente(): void {
		$this->pedir( 'POST', 10, 42 );
		$this->pedir( 'POST', 10, 42 );

		self::assertSame( array( 42 ), $this->bajas[10] );
	}

	/** @testdox El enlace global y el de una serie son URLs distintas. */
	public function test_el_enlace_global_y_el_de_serie_son_distintos(): void {
		self::assertNotSame( UnfollowHandler::url_for_all( 10 ), UnfollowHandler::url_for( 10, 42 ) );
		self::assertSame( UnfollowHandler::url_for( 10, 0 ), UnfollowHandler::url_for_all( 10 ) );
	}

	/**
	 * N-bis-unsub (2026-09-19, medido en producción): el borde cachea diez
	 * minutos cualquier HTML de invitado servido en `home_url('/')` aunque el
	 * origen mande `no-store` (`cf-cache-status: HIT` medido), y dos clientes
	 * que abren enlaces de baja distintos durante esa ventana pueden recibir la
	 * misma respuesta cacheada. La zona deja pasar `/mi-cuenta/` sin cachear.
	 *
	 * @testdox El enlace de baja va bajo Mi cuenta, no la portada.
	 */
	public function test_el_enlace_va_bajo_mi_cuenta_no_la_portada(): void {
		self::assertStringStartsWith( 'https://akibara.test/mi-cuenta/?', UnfollowHandler::url_for( 10, 42 ) );
		self::assertStringNotContainsString( 'https://akibara.test/?', UnfollowHandler::url_for( 10, 42 ) );
	}

	/**
	 * El query var se lee directo de `$_GET` en `handle()`, no de
	 * `get_query_var()`: un enlace YA ENVIADO con la URL vieja (home + query)
	 * sigue funcionando sin importar bajo qué ruta llegue la petición.
	 *
	 * @testdox [NEG] Un enlace viejo (home + query) sigue dando de baja.
	 */
	public function test_un_enlace_viejo_con_la_url_de_la_portada_sigue_funcionando(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = array(
			UnfollowHandler::QUERY_USER => '10',
			UnfollowHandler::QUERY_TERM => '42',
			UnfollowHandler::QUERY_KEY  => UnfollowHandler::token( 10, 42 ),
		);

		ob_start();
		( new UnfollowHandler() )->handle();
		ob_end_clean();

		self::assertSame( array( 42 ), $this->bajas[10] );
	}
}
