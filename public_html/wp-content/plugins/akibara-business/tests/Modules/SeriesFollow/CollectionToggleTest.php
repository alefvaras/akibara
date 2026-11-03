<?php
/**
 * El interruptor de «Mi colección» y su ruta REST.
 *
 * A diferencia de la captura de la lista de espera —pública, porque recoge un
 * correo anónimo— esta ruta MUTA el estado del titular de la sesión. El
 * `user_id` sale siempre de la sesión y nunca del cuerpo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WP_REST_Server' ) ) {
		/** Stub mínimo del servidor REST: sólo la constante de métodos usada. */
		class WP_REST_Server {
			public const CREATABLE = 'POST';
		}
	}

	// `WP_REST_Request` vive en `tests/wp-stubs.php` desde el 2026-09-06: su copia
	// aquí sólo existía si este fichero se cargaba antes que quien la necesitara.

	// `WP_REST_Response` subió también a `tests/wp-stubs.php` el 2026-09-19:
	// `ContactoSubmitEmailTest` la usaba sin cargarla y corrida sola caía.
}

namespace Akibara\Business\Tests\Modules\SeriesFollow {

	use Akibara\Business\Modules\SeriesFollow\CollectionToggle;
	use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class CollectionToggleTest extends TestCase {

		private const SESION = 10;

		/** @var array<int, array<int, int>> user_id ⇒ term_ids dados de baja. */
		private array $bajas = array();

		/** @var array<int, string> user_id ⇒ '1' si baja global. */
		private array $bajas_globales = array();

		private bool $logueado = true;

		private bool $nonce_valido = true;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->bajas          = array();
			$this->bajas_globales = array();
			$this->logueado       = true;
			$this->nonce_valido   = true;

			Functions\when( 'is_user_logged_in' )->alias( fn (): bool => $this->logueado );
			Functions\when( 'wp_verify_nonce' )->alias( fn (): bool => $this->nonce_valido );
			Functions\when( 'get_current_user_id' )->alias( fn (): int => $this->logueado ? self::SESION : 0 );
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
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @param array<string, mixed> $params Cuerpo de la petición. */
		private function peticion( array $params ): \WP_REST_Request {
			return new \WP_REST_Request( $params, array( 'X-WP-Nonce' => 'nonce-valido' ) );
		}

		/** @testdox [NEG] La ruta rechaza a un invitado. */
		public function test_la_ruta_rechaza_a_un_invitado(): void {
			$this->logueado = false;

			self::assertFalse(
				( new CollectionToggle() )->can_toggle( $this->peticion( array( 'term_id' => 42 ) ) )
			);
		}

		/** @testdox [NEG] La ruta rechaza un nonce inválido. */
		public function test_la_ruta_rechaza_un_nonce_invalido(): void {
			$this->nonce_valido = false;

			self::assertFalse(
				( new CollectionToggle() )->can_toggle( $this->peticion( array( 'term_id' => 42 ) ) )
			);
		}

		/** @testdox Con sesión y nonce válidos, la ruta acepta. */
		public function test_la_ruta_acepta_a_un_cliente_con_sesion(): void {
			self::assertTrue(
				( new CollectionToggle() )->can_toggle( $this->peticion( array( 'term_id' => 42 ) ) )
			);
		}

		/** @testdox Desactivar el interruptor da de baja al cliente de esa serie. */
		public function test_apagar_el_interruptor_da_de_baja_esa_serie(): void {
			$respuesta = ( new CollectionToggle() )->handle(
				$this->peticion(
					array(
						'term_id' => 42,
						'follow'  => false,
					)
				)
			);

			self::assertSame( 200, $respuesta->get_status() );
			self::assertFalse( $respuesta->get_data()['following'] );
			self::assertSame( array( 42 ), $this->bajas[ self::SESION ] );
		}

		/** @testdox Encender el interruptor quita la baja de esa serie. */
		public function test_encender_el_interruptor_quita_la_baja(): void {
			$this->bajas[ self::SESION ] = array( 42, 43 );

			$respuesta = ( new CollectionToggle() )->handle(
				$this->peticion(
					array(
						'term_id' => 42,
						'follow'  => true,
					)
				)
			);

			self::assertTrue( $respuesta->get_data()['following'] );
			self::assertSame( array( 43 ), $this->bajas[ self::SESION ] );
		}

		/**
		 * Sin esto, el interruptor diría «sigues esta serie» mientras la cabecera
		 * global sigue callando TODOS los avisos del cliente.
		 *
		 * @testdox Encender una serie levanta también la baja global.
		 */
		public function test_encender_una_serie_levanta_la_baja_global(): void {
			$this->bajas_globales[ self::SESION ] = '1';

			( new CollectionToggle() )->handle(
				$this->peticion(
					array(
						'term_id' => 42,
						'follow'  => true,
					)
				)
			);

			self::assertArrayNotHasKey( self::SESION, $this->bajas_globales );
		}

		/**
		 * EL CANDADO DE LA SESIÓN: el `user_id` no viaja en el cuerpo, así que
		 * mandar el de otro cliente no lo toca.
		 *
		 * @testdox El toggle sólo muta al usuario de la sesión.
		 */
		public function test_el_toggle_solo_muta_el_usuario_de_la_sesion(): void {
			( new CollectionToggle() )->handle(
				$this->peticion(
					array(
						'term_id' => 42,
						'follow'  => false,
						'user_id' => 999,
					)
				)
			);

			self::assertArrayHasKey( self::SESION, $this->bajas );
			self::assertArrayNotHasKey( 999, $this->bajas );
		}

		/** @testdox [NEG] Sin serie válida responde 400 y no toca nada. */
		public function test_sin_serie_valida_responde_400(): void {
			$respuesta = ( new CollectionToggle() )->handle( $this->peticion( array( 'follow' => false ) ) );

			self::assertSame( 400, $respuesta->get_status() );
			self::assertSame( array(), $this->bajas );
		}

		/**
		 * El cuerpo puede llegar como JSON (`true`) o como form-data (`"1"`).
		 *
		 * @testdox Las dos formas de «seguir» se entienden igual.
		 * @dataProvider valoresAfirmativos
		 * @param mixed $valor Valor del campo `follow`.
		 */
		public function test_las_formas_afirmativas_se_entienden_igual( $valor ): void {
			$this->bajas[ self::SESION ] = array( 42 );

			$respuesta = ( new CollectionToggle() )->handle(
				$this->peticion(
					array(
						'term_id' => 42,
						'follow'  => $valor,
					)
				)
			);

			self::assertTrue( $respuesta->get_data()['following'] );
		}

		/** @return array<string, array{0: mixed}> */
		public static function valoresAfirmativos(): array {
			return array(
				'json bool'   => array( true ),
				'form-data 1' => array( '1' ),
				'texto true'  => array( 'true' ),
			);
		}
	}
}
