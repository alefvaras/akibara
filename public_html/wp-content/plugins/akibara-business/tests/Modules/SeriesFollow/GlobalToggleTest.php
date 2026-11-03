<?php
/**
 * El interruptor GLOBAL «Avisos de mi colección» de Mi cuenta.
 *
 * Es un formulario POST con nonce, sin JavaScript. Lo que hay que probar es
 * que sólo muta al titular de la sesión, sólo con nonce suyo, sólo por POST y
 * sólo con los dos valores que entiende; que escribe la MISMA usermeta que la
 * baja por correo; que encender NO deshace las bajas por serie (y lo dice); y
 * que las ramas de fallo (sin sesión, nonce caducado o ajeno) no mutan pero
 * tampoco se quedan mudas: aviso de error y redirección, como el éxito.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\GlobalToggle;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class GlobalToggleTest extends TestCase {

	private const SESION = 10;

	/** @var array<int, array<int, int>> user_id ⇒ term_ids dados de baja. */
	private array $bajas = array();

	/** @var array<int, string> user_id ⇒ '1' si baja global. */
	private array $bajas_globales = array();

	/** @var array<int, array{0: string, 1: string}> Avisos añadidos con wc_add_notice. */
	private array $avisos = array();

	/** @var array<int, string> Destinos de wp_safe_redirect. */
	private array $redirecciones = array();

	private bool $logueado = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bajas          = array();
		$this->bajas_globales = array();
		$this->avisos         = array();
		$this->redirecciones  = array();
		$this->logueado       = true;

		Functions\when( 'is_user_logged_in' )->alias( fn (): bool => $this->logueado );
		Functions\when( 'get_current_user_id' )->alias( fn (): int => $this->logueado ? self::SESION : 0 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( (string) $value ) );
		// El nonce válido es SÓLO el del usuario de la sesión: 'nonce-<id>'.
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn ( string $nonce, string $action ): bool => 'nonce-' . substr( $action, strlen( GlobalToggle::NONCE_ACTION ) ) === $nonce
		);
		Functions\when( 'wc_add_notice' )->alias(
			function ( string $message, string $type = 'success' ): void {
				$this->avisos[] = array( $message, $type );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): bool {
				$this->redirecciones[] = $location;
				return true;
			}
		);
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn ( string $slug, string $value = '', string $permalink = '' ): string =>
				( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : 'https://akibara.test/mi-cuenta/' ) . $slug . '/'
		);
		// El `exit` tras la redirección se corta con el filtro, como en la baja.
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => 'akb_sf_global_toggle_should_exit' === $hook ? false : $value
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
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ): bool {
				if ( FollowRegistry::META_OPTOUT_ALL === $key ) {
					unset( $this->bajas_globales[ $user_id ] );
				}

				return true;
			}
		);
	}

	protected function tearDown(): void {
		// Se VACÍA, no se destruye: `unset($_POST)` mata la superglobal para el
		// resto del proceso.
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string      $metodo Método HTTP.
	 * @param string|null $valor  Valor del campo (null = sin el campo).
	 * @param string|null $nonce  Nonce enviado (null = sin nonce).
	 */
	private function enviar( string $metodo, ?string $valor, ?string $nonce = 'nonce-10' ): void {
		$_SERVER['REQUEST_METHOD'] = $metodo;
		$_POST                     = array();
		if ( null !== $valor ) {
			$_POST[ GlobalToggle::FIELD ] = $valor;
		}
		if ( null !== $nonce ) {
			$_POST[ GlobalToggle::NONCE_FIELD ] = $nonce;
		}

		( new GlobalToggle() )->handle();
	}

	/** @testdox register() engancha template_redirect en prioridad 1, como la baja por correo. */
	public function test_register_engancha_template_redirect_en_prioridad_1(): void {
		$toggle = new GlobalToggle();

		Monkey\Actions\expectAdded( 'template_redirect' )->once()->with( array( $toggle, 'handle' ), 1 );

		$toggle->register();
		$this->addToAssertionCount( 1 );
	}

	/** @testdox 'off' escribe la baja global del usuario de la sesión y redirige a la Colección. */
	public function test_off_escribe_la_baja_global_y_redirige(): void {
		$this->enviar( 'POST', 'off' );

		self::assertSame( '1', $this->bajas_globales[ self::SESION ] );
		self::assertSame( array( 'https://akibara.test/mi-cuenta/coleccion/' ), $this->redirecciones );
	}

	/**
	 * La MISMA usermeta que escribe el List-Unsubscribe del correo: correo y
	 * pantalla no pueden contradecirse.
	 *
	 * @testdox 'off' escribe exactamente lo que escribe la baja global por correo.
	 */
	public function test_off_escribe_lo_mismo_que_la_baja_por_correo(): void {
		$this->enviar( 'POST', 'off' );
		$desde_pantalla = $this->bajas_globales;

		$this->bajas_globales = array();
		FollowRegistry::opt_out_all( self::SESION );

		self::assertSame( $this->bajas_globales, $desde_pantalla );
	}

	/** @testdox 'on' borra la baja global y NO toca las bajas por serie. */
	public function test_on_borra_la_baja_global_y_respeta_las_bajas_por_serie(): void {
		$this->bajas_globales[ self::SESION ] = '1';
		$this->bajas[ self::SESION ]          = array( 42, 43 );

		$this->enviar( 'POST', 'on' );

		self::assertArrayNotHasKey( self::SESION, $this->bajas_globales );
		self::assertSame( array( 42, 43 ), $this->bajas[ self::SESION ], 'Las bajas por serie son decisiones explícitas: encender el global no las deshace.' );
	}

	/**
	 * «Te avisaremos … de tus series» sería falso para las series apagadas una
	 * a una: el aviso de éxito del dueño va intacto y le sigue uno aparte que
	 * lo aclara. Sin bajas por serie, sólo el del dueño.
	 *
	 * @testdox 'on' con series apagadas una a una avisa que ésas siguen apagadas; sin ellas, no.
	 */
	public function test_on_con_bajas_por_serie_avisa_que_siguen_apagadas(): void {
		$this->bajas[ self::SESION ] = array( 42 );
		$this->enviar( 'POST', 'on' );

		self::assertSame(
			array(
				array( GlobalToggle::NOTICE_ON, 'success' ),
				array( 'Las series que apagaste una a una siguen apagadas. Las reactivas desde su tarjeta, aquí en tu colección.', 'notice' ),
			),
			$this->avisos
		);

		$this->avisos                = array();
		$this->bajas[ self::SESION ] = array();
		$this->enviar( 'POST', 'on' );

		self::assertSame( array( array( GlobalToggle::NOTICE_ON, 'success' ) ), $this->avisos );
	}

	/** @testdox opt_in_all es idempotente sobre un usuario sin la meta. */
	public function test_opt_in_all_es_idempotente(): void {
		FollowRegistry::opt_in_all( self::SESION );
		FollowRegistry::opt_in_all( self::SESION );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame( array(), $this->bajas );
	}

	/** @testdox Los avisos de éxito llevan el texto exacto del dueño. */
	public function test_los_avisos_llevan_el_texto_exacto(): void {
		$this->enviar( 'POST', 'off' );
		$this->enviar( 'POST', 'on' );

		self::assertSame(
			array(
				array( 'Listo: apagamos los avisos de tu colección. No te escribiremos cuando salgan tomos nuevos.', 'success' ),
				array( 'Listo: te avisaremos por correo cuando salga el siguiente tomo de tus series.', 'success' ),
			),
			$this->avisos
		);
	}

	/**
	 * EL CANDADO DE LA SESIÓN: el `user_id` no viaja en el cuerpo.
	 *
	 * @testdox Sólo muta al usuario de la sesión, aunque el cuerpo traiga otro id.
	 */
	public function test_solo_muta_al_usuario_de_la_sesion(): void {
		$_POST['user_id'] = 999;
		$this->enviar( 'POST', 'off' );

		self::assertArrayHasKey( self::SESION, $this->bajas_globales );
		self::assertArrayNotHasKey( 999, $this->bajas_globales );
	}

	/**
	 * CAMBIO DE CONTRATO DELIBERADO (2026-09-11). Hasta hoy el POST de un
	 * invitado seguía su curso sin aviso ni redirección: la respuesta se
	 * pintaba en la URL del POST y el cliente no sabía que no pasó nada. Ahora
	 * no muta, deja un aviso de error y redirige a la Colección (que le pide
	 * iniciar sesión).
	 *
	 * @testdox [NEG] Un invitado no muta nada, pero se le avisa y se le redirige.
	 */
	public function test_un_invitado_no_muta(): void {
		$this->logueado = false;

		$this->enviar( 'POST', 'off' );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame( array( array( GlobalToggle::NOTICE_SESSION, 'error' ) ), $this->avisos );
		self::assertSame( array( 'https://akibara.test/mi-cuenta/coleccion/' ), $this->redirecciones );
	}

	/**
	 * El nonce caduca con el tick de WordPress (12-24 h): una pestaña de
	 * Colección abierta desde ayer llega aquí. No muta, pero no puede quedarse
	 * muda ni dejar el POST sin redirigir (F5 = reenvío del formulario).
	 *
	 * @testdox [NEG] Sin nonce no muta nada, avisa que hay que reintentar y redirige.
	 */
	public function test_sin_nonce_no_muta(): void {
		$this->enviar( 'POST', 'off', null );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame(
			array( array( 'No pudimos guardar el cambio: la página llevaba demasiado tiempo abierta. Vuelve a intentarlo.', 'error' ) ),
			$this->avisos
		);
		self::assertSame( array( 'https://akibara.test/mi-cuenta/coleccion/' ), $this->redirecciones );
	}

	/**
	 * El nonce va atado al id: un formulario copiado de otro cliente no sirve.
	 *
	 * @testdox [NEG] El nonce de otro usuario no muta nada, y también avisa y redirige.
	 */
	public function test_el_nonce_de_otro_usuario_no_muta(): void {
		$this->enviar( 'POST', 'off', 'nonce-11' );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame( array( array( GlobalToggle::NOTICE_RETRY, 'error' ) ), $this->avisos );
		self::assertSame( array( 'https://akibara.test/mi-cuenta/coleccion/' ), $this->redirecciones );
	}

	/** @testdox [NEG] Un GET no muta nada: sólo el POST del formulario. */
	public function test_un_get_no_muta(): void {
		$this->enviar( 'GET', 'off' );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame( array(), $this->redirecciones );
	}

	/** @testdox [NEG] Un valor distinto de on/off se ignora. */
	public function test_un_valor_desconocido_se_ignora(): void {
		$this->bajas_globales[ self::SESION ] = '1';

		$this->enviar( 'POST', 'maybe' );
		$this->enviar( 'POST', '1' );
		$this->enviar( 'POST', '' );

		self::assertSame( array( self::SESION => '1' ), $this->bajas_globales );
		self::assertSame( array(), $this->avisos );
	}

	/** @testdox [NEG] Un POST ajeno (sin el campo) sigue su curso sin tocar nada. */
	public function test_un_post_ajeno_no_se_intercepta(): void {
		$this->enviar( 'POST', null );

		self::assertSame( array(), $this->bajas_globales );
		self::assertSame( array(), $this->redirecciones );
	}
}
