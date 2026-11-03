<?php
/**
 * R3-dc2c02a9 (2026-09-19): el respaldo sin JavaScript del formulario de contacto.
 *
 * El `<form>` del tema ahora hace un submit NATIVO a `admin-post.php`
 * (`method="post"`, `action=akb_contacto`) para cuando el JS no llegó a
 * interceptarlo. Sin un handler `admin_post_(nopriv_)akb_contacto`, WordPress
 * respondía «Método no permitido» y el mensaje del cliente se perdía en
 * silencio — y peor, el submit por defecto SIN esos atributos viajaba como
 * GET, con nombre/email/mensaje expuestos en la URL.
 *
 * Esta prueba fija `ContactoSubmit::handle_admin_post()` (vía su método interno
 * `admin_post_redirect_url()`, que aísla el cálculo del `exit` que lo sigue):
 * misma validación que el REST (`process()`), redirect SIEMPRE con
 * `?akb_contacto=ok|error` — nunca datos del cliente en la URL — y sólo al
 * propio sitio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoSubmit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ContactoAdminPostBackupTest extends TestCase {

	/** El `$wpdb` global que había antes: se devuelve al terminar. */
	private $wpdb_previo = null;

	private bool $habia_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->habia_wpdb  = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;

		// Mismo doblaje que ContactoSubmitEmailTest: lo de ANTES de la
		// validación se deja pasar (rate limit, honeypot ya cubiertos aparte),
		// y lo de DESPUÉS (bitácora + notificación) se doblega al mínimo.
		Functions\when( 'sanitize_email' )->alias(
			static function ( string $valor ): string {
				$limpio = filter_var( trim( $valor ), FILTER_SANITIZE_EMAIL );
				return is_string( $limpio ) && false !== filter_var( $limpio, FILTER_VALIDATE_EMAIL ) ? $limpio : '';
			}
		);
		Functions\when( 'is_email' )->alias( static fn( string $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : false );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $u, int $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_add' )->justReturn( true );
		Functions\when( 'wp_cache_incr' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $valor ) => $valor );
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias( static fn( $v ) => is_scalar( $v ) ? (string) $v : serialize( $v ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_option' )->alias( static fn( string $c, $defecto = false ) => $defecto );
		Functions\when( 'wp_trim_words' )->alias(
			static function ( string $texto, int $palabras = 55, ?string $mas = null ): string {
				$trozos = preg_split( '/\s+/', trim( $texto ) ) ?: array();
				return count( $trozos ) <= $palabras ? implode( ' ', $trozos ) : implode( ' ', array_slice( $trozos, 0, $palabras ) ) . (string) $mas;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'prueba-0000-0000-0000-000000000000' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $v ): string => strip_tags( $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'current_time' )->alias( static fn( string $t = 'mysql' ) => 'mysql' === $t ? '2026-09-19 12:00:00' : time() );

		// Propios del respaldo admin-post: NavigationLinks::contact_url() cae
		// a home_url('/contacto/') porque no hay página 'contacto' en el doble.
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_get_referer' )->alias( static fn() => $_SERVER['HTTP_REFERER'] ?? false );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $clave, $valor = null, ?string $url = null ) {
				if ( is_array( $clave ) ) {
					$kv  = $clave;
					$url = (string) $valor;
				} else {
					$kv = array( $clave => $valor );
				}
				$sep = false === strpos( (string) $url, '?' ) ? '?' : '&';
				return $url . $sep . http_build_query( $kv );
			}
		);

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public string $options = 'wp_options';
			public string $last_error = '';
			/** @param mixed ...$args */
			public function prepare( string $consulta, ...$args ): string {
				unset( $args );
				return $consulta;
			}
			public function query( string $consulta ) {
				return 0 === strpos( $consulta, 'INSERT IGNORE INTO wp_options' ) ? 1 : 0;
			}
			public function get_var( string $consulta ) {
				unset( $consulta );
				return '0';
			}
			public function get_col( string $consulta ) {
				unset( $consulta );
				return array();
			}
			public function esc_like( string $texto ) {
				return addcslashes( $texto, '_%\\' );
			}
		};
	}

	protected function tearDown(): void {
		if ( $this->habia_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_POST = array();
		unset( $_SERVER['HTTP_REFERER'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $post    Lo que llega en $_POST.
	 * @param string               $referer Lo que llega en $_SERVER['HTTP_REFERER'].
	 */
	private function redirectUrl( array $post, string $referer ): string {
		$_POST                     = $post;
		$_SERVER['HTTP_REFERER'] = $referer;

		$method = new ReflectionMethod( ContactoSubmit::class, 'admin_post_redirect_url' );
		$method->setAccessible( true );

		return (string) $method->invoke( new ContactoSubmit() );
	}

	/** @testdox Envío válido, referer del propio sitio: vuelve AL REFERER con `?akb_contacto=ok`. */
	public function test_valid_submission_redirects_to_the_referer_with_ok(): void {
		$url = $this->redirectUrl(
			array( 'nombre' => 'Juan', 'email' => 'juan@akibara.cl', 'mensaje' => 'Hola' ),
			'https://akibara.cl/contacto/'
		);

		self::assertStringStartsWith( 'https://akibara.cl/contacto/', $url );
		self::assertStringContainsString( 'akb_contacto=ok', $url );
	}

	/** @testdox Campo faltante: vuelve con `?akb_contacto=error`, nunca con los datos del cliente en la URL. */
	public function test_missing_field_redirects_with_error_and_no_customer_data(): void {
		$url = $this->redirectUrl(
			array( 'nombre' => 'Juan', 'email' => '', 'mensaje' => 'Hola' ),
			'https://akibara.cl/contacto/'
		);

		self::assertStringContainsString( 'akb_contacto=error', $url );
		self::assertStringNotContainsString( 'nombre=', $url );
		self::assertStringNotContainsString( 'mensaje=', $url );
		self::assertStringNotContainsString( 'Hola', $url );
	}

	/** @testdox El honeypot disparado redirige con `ok` — igual de silencioso que el REST. */
	public function test_honeypot_redirects_as_if_it_succeeded(): void {
		$url = $this->redirectUrl(
			array( 'nombre' => 'Bot', 'email' => 'bot@spam.com', 'mensaje' => 'spam', 'akb_hp_url' => 'http://x.com' ),
			'https://akibara.cl/contacto/'
		);

		self::assertStringContainsString( 'akb_contacto=ok', $url );
	}

	/**
	 * @testdox Referer de otro sitio: el origen se rechaza (`bad_origin`→error) Y la redirección NUNCA sale del propio dominio.
	 */
	public function test_a_foreign_referer_is_rejected_and_never_used_as_redirect_target(): void {
		$url = $this->redirectUrl(
			array( 'nombre' => 'Juan', 'email' => 'juan@akibara.cl', 'mensaje' => 'Hola' ),
			'https://evil.example/harvest'
		);

		self::assertStringContainsString( 'akb_contacto=error', $url, 'El chequeo de origen usa el MISMO referer.' );
		self::assertStringStartsWith( 'https://akibara.cl/contacto/', $url, 'Nunca se redirige a un dominio ajeno.' );
		self::assertStringNotContainsString( 'evil.example', $url );
	}

	/** @testdox Sin ninguna cabecera Referer (endpoint público, sin señal de origen): el envío pasa y cae a la página de Contacto. */
	public function test_without_any_referer_it_still_succeeds_and_falls_back_to_the_contact_page(): void {
		$url = $this->redirectUrl(
			array( 'nombre' => 'Juan', 'email' => 'juan@akibara.cl', 'mensaje' => 'Hola' ),
			''
		);

		self::assertStringContainsString( 'akb_contacto=ok', $url );
		self::assertStringStartsWith( 'https://akibara.cl/contacto/', $url );
	}
}
