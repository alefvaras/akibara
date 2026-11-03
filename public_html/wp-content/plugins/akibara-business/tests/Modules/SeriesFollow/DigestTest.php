<?php
/**
 * La corrida del digest: UN correo por cliente, con todas sus series dentro.
 *
 * Aquí viven los dos candados que sostienen la feature en producción: el import
 * masivo de una tarde no puede convertirse en cincuenta correos, y una corrida
 * que revienta a medias no puede dejar filas `sent` sin correo — `uniq_event`
 * prohíbe reencolarlas, así que ese aviso se perdería para siempre.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use Akibara\Business\Modules\SeriesFollow\DigestQueue;
use Akibara\Business\Modules\SeriesFollow\DigestRunner;
use Akibara\Business\Modules\SeriesFollow\FollowerFinder;
use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Tests\Support\SeriesFollowFixedEta;
use Akibara\Business\Tests\Support\SeriesFollowFollowersWpdb;
use Akibara\Business\Tests\Support\SeriesFollowQueueWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

/**
 * Doble del correo que registra cada envío. El constructor se anula a propósito:
 * el real toca `AKIBARA_BUSINESS_DIR` y los settings de WooCommerce, que no son
 * lo que estas pruebas miden.
 */
final class DigestSpyEmail extends SeriesFollowEmail {

	/** @var array<int, array{user_id:int, groups:array<int, array<string, mixed>>}> */
	public array $enviados = array();

	public bool $habilitado = true;

	public bool $acepta = true;

	public bool $revienta = false;

	// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	public function __construct() {}

	public function is_enabled(): bool {
		return $this->habilitado;
	}

	/**
	 * @param int                              $user_id Cliente.
	 * @param array<int, array<string, mixed>> $groups  Bloques.
	 * @return bool
	 */
	public function trigger( int $user_id, array $groups ): bool {
		if ( $this->revienta ) {
			throw new \RuntimeException( 'el mailer se cayó' );
		}

		$this->enviados[] = compact( 'user_id', 'groups' );

		return $this->acepta;
	}
}

final class DigestTest extends TestCase {

	private const TERM_ONE_PIECE = 42;
	private const TERM_BERSERK   = 43;
	private const TERM_MONSTER   = 44;

	private SeriesFollowQueueWpdb $queue_db;

	private SeriesFollowFollowersWpdb $orders_db;

	private DigestSpyEmail $email;

	/** @var array<int, string> product_id ⇒ estado del post. */
	private array $estados = array();

	/** @var array<int, int> user_id ⇒ último envío. */
	private array $ultimo_envio = array();

	/** @var bool Ya hay una corrida agendada antes de que arranque el caso. */
	private bool $digest_pendiente = false;

	/** @var array<int, array<int, int>> user_id ⇒ term_ids dados de baja. */
	private array $bajas = array();

	/** @var array<int, array{hook:string, args:array<string,mixed>}> */
	private array $scheduled = array();

	/** @var array<int, array{message:string, context:array<string,mixed>}> */
	private array $logs = array();

	private string $flag = 'yes';

	private int $max_series = 5;

	/** @var string|null Excepción a lanzar al armar el bloque. */
	private ?string $error_al_armar = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->queue_db       = new SeriesFollowQueueWpdb();
		$this->orders_db      = new SeriesFollowFollowersWpdb();
		$this->email          = new DigestSpyEmail();
		$this->estados        = array();
		$this->ultimo_envio   = array();
		$this->digest_pendiente = false;
		$this->bajas          = array();
		$this->scheduled      = array();
		$this->flag           = 'yes';
		$this->max_series     = 5;
		$this->error_al_armar = null;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_the_terms' )->justReturn( array() );
		Functions\when( 'has_term' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias( static fn ( int $id ): string => 'https://akibara.test/p/' . $id );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		Functions\when( 'wp_salt' )->justReturn( 'salt-de-prueba' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		// Se registra lo que se loguea: S-eb98936e exige una traza cuando el
		// correo está deshabilitado en WC, y descartarla en silencio (como
		// hacía este doble) haría pasar una prueba que debe caer sin el fix.
		$this->logs = array();
		$record     = function ( string $message, array $context = array() ): void {
			$this->logs[] = compact( 'message', 'context' );
		};
		Functions\when( 'wc_get_logger' )->justReturn(
			new class( $record ) {
				/** @var callable */
				private $record;

				public function __construct( callable $record ) {
					$this->record = $record;
				}

				/** @param array<string, mixed> $context */
				public function warning( string $message, array $context = array() ): void {
					( $this->record )( $message, $context );
				}
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( string $option, $default_value = false ) {
				if ( SeriesFollow::OPTION_ENABLED === $option ) {
					return $this->flag;
				}
				if ( SeriesFollow::OPTION_MAX_SERIES === $option ) {
					return $this->max_series;
				}
				if ( SeriesFollow::OPTION_CAP_DAYS === $option ) {
					return 7;
				}

				return $default_value;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) {
				if ( FollowRegistry::META_OPTOUT === $key ) {
					return $this->bajas[ $user_id ] ?? array();
				}
				if ( FollowRegistry::META_LAST_SENT === $key ) {
					return $this->ultimo_envio[ $user_id ] ?? 0;
				}

				return '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				if ( FollowRegistry::META_LAST_SENT === $key ) {
					$this->ultimo_envio[ $user_id ] = (int) $value;
				}
				if ( FollowRegistry::META_OPTOUT === $key ) {
					$this->bajas[ $user_id ] = (array) $value;
				}

				return true;
			}
		);
		Functions\when( 'get_post_status' )->alias(
			fn ( int $id ): string => $this->estados[ $id ] ?? 'publish'
		);
		Functions\when( 'get_term' )->alias(
			static fn ( int $term_id ): object => (object) array(
				'term_id' => $term_id,
				'name'    => 'Serie ' . $term_id,
			)
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ): WC_Product {
				$product = new class() extends WC_Product {
					public int $akb_product_id = 0;

					public function get_id(): int {
						return $this->akb_product_id;
					}

					public function get_name(): string {
						return 'Tomo ' . $this->akb_product_id;
					}

					public function get_image_id(): int {
						return 0;
					}

					public function get_price_html(): string {
						return '$12.990';
					}
				};
				$product->akb_product_id = $id;

				return $product;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args = array() ): int {
				// El `timestamp` se guarda: el doble anterior lo descartaba, así
				// que ninguna prueba podía ver CUÁNDO se agenda la corrida
				// siguiente — y ahí vivía un giro en vacío de una vez por minuto
				// durante días.
				$this->scheduled[] = compact( 'hook', 'args', 'timestamp' );
				return count( $this->scheduled );
			}
		);
		// El doble modela la cola de PENDIENTES, que es lo que `schedule_digest()`
		// pregunta ahora. `as_next_scheduled_action()` no vale para eso: devuelve
		// `true` con una acción del mismo hook EN CURSO, y la corrida se contaba a
		// sí misma.
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query ): array {
				if ( $this->digest_pendiente ) {
					return array( 1 );
				}
				foreach ( $this->scheduled as $cita ) {
					if ( ( $cita['hook'] ?? '' ) === ( $query['hook'] ?? '' ) ) {
						return array( 1 );
					}
				}
				return array();
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function runner( ?callable $mailer_ready = null ): DigestRunner {
		$collection = new CustomerCollection( $this->orders_db );

		return new DigestRunner(
			new DigestQueue( $this->queue_db, 'wp_akb_series_follow_queue' ),
			new FollowerFinder( $collection, $this->orders_db ),
			$collection,
			$this->cutoff(),
			fn (): DigestSpyEmail => $this->email,
			$mailer_ready
		);
	}

	private function cutoff(): CutoffPhrase {
		// El fallo se inyecta desde el proveedor de ETA y no desde un doble de
		// `CutoffPhrase`: ahí es donde reventaría de verdad (una lectura de
		// term-meta caída dentro del cálculo), y el camino que recorre la
		// excepción hasta el `try` del runner es exactamente el de producción.
		return new CutoffPhrase(
			new SeriesFollowFixedEta( null, $this->error_al_armar ),
			new PedidoPolicy()
		);
	}

	private function queue(): DigestQueue {
		return new DigestQueue( $this->queue_db, 'wp_akb_series_follow_queue' );
	}

	private function encolar( int $user_id, int $product_id, int $term_id, int $volume ): void {
		$this->queue()->queue(
			array(
				array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
					'term_id'    => $term_id,
					'volume'     => $volume,
				),
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	/**
	 * Segundos entre el reloj de la corrida y la próxima cita agendada.
	 *
	 * @return int
	 */
	private function espera_agendada(): int {
		$ultima = null;
		foreach ( $this->scheduled as $cita ) {
			if ( SeriesFollow::HOOK_DIGEST === ( $cita['hook'] ?? '' ) ) {
				$ultima = $cita;
			}
		}

		self::assertNotNull( $ultima, 'No se agendó ninguna corrida siguiente.' );

		return max( 0, (int) $ultima['timestamp'] - time() );
	}

	private function filas( string $status ): array {
		return $this->queue_db->rows_with_status( $status );
	}

	/** @testdox Un cliente con TRES series recibe UN solo correo con tres bloques. */
	public function test_un_cliente_con_tres_series_recibe_un_solo_correo_con_tres_bloques(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );
		$this->encolar( 10, 7018, self::TERM_MONSTER, 18 );

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados );
		self::assertSame( 10, $this->email->enviados[0]['user_id'] );
		self::assertCount( 3, $this->email->enviados[0]['groups'] );
		self::assertCount( 3, $this->filas( DigestQueue::STATUS_SENT ) );
	}

	/** @testdox Los bloques salen ordenados por tomo descendente. */
	public function test_los_bloques_salen_del_tomo_mas_nuevo_al_mas_viejo(): void {
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();

		self::assertSame(
			array( 107, 42 ),
			array_column( $this->email->enviados[0]['groups'], 'volume' )
		);
	}

	/**
	 * EL CANDADO DEL IMPORT MASIVO. Cincuenta tomos publicados en una tarde no
	 * pueden ser cincuenta correos: la coalescencia con `args = null` hace que
	 * del tomo 2 al 50 todos vean la MISMA corrida pendiente.
	 *
	 * @testdox Cincuenta tomos publicados producen UN correo por cliente.
	 */
	public function test_cincuenta_tomos_publicados_producen_un_correo_por_cliente(): void {
		for ( $tomo = 1; $tomo <= 50; $tomo++ ) {
			$this->encolar( 10, 8000 + $tomo, 100 + $tomo, $tomo );
		}
		$this->max_series = 50;

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados, 'Cincuenta publicaciones, un correo.' );
		self::assertCount( 50, $this->email->enviados[0]['groups'] );
	}

	/** @testdox Una corrida ya pendiente impide agendar una segunda. */
	public function test_una_sola_corrida_pendiente_para_todos_los_scans(): void {
		$runner = $this->runner();
		$this->digest_pendiente = true;

		for ( $tomo = 1; $tomo <= 50; $tomo++ ) {
			$this->encolar( 10, 8000 + $tomo, 100 + $tomo, $tomo );
		}
		$runner->run();

		self::assertSame(
			array(),
			array_filter(
				$this->scheduled,
				static fn ( array $job ): bool => SeriesFollow::HOOK_DIGEST === $job['hook']
			),
			'Con una corrida en vuelo, ninguna otra se agenda.'
		);
	}

	/** @testdox [NEG] El tope semanal deja las filas PENDING y no manda. */
	public function test_tope_semanal_deja_las_filas_pending_y_no_manda(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->ultimo_envio[10] = time() - DAY_IN_SECONDS;

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
	}

	/** @testdox Lo pendiente por el tope sale en la corrida siguiente. */
	public function test_lo_pendiente_por_el_tope_sale_en_la_corrida_siguiente(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->ultimo_envio[10] = time() - DAY_IN_SECONDS;
		$this->runner()->run();

		$this->ultimo_envio[10] = time() - ( 8 * DAY_IN_SECONDS );
		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_SENT ) );
	}

	/** @testdox [NEG] El opt-out entre el encolado y el envío descarta esa serie y conserva las otras. */
	public function test_opt_out_entre_el_encolado_y_el_envio_descarta_esa_serie(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );
		$this->bajas[10] = array( self::TERM_BERSERK );

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados[0]['groups'] );
		self::assertSame( self::TERM_ONE_PIECE, $this->email->enviados[0]['groups'][0]['term_id'] );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_DROPPED ) );
	}

	/** @testdox [NEG] Comprar el tomo entre el encolado y el envío descarta la fila. */
	public function test_comprar_el_tomo_entre_el_encolado_y_el_envio_descarta_la_fila(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );
		$this->orders_db->lineas[] = array(
			'user_id'    => 10,
			'product_id' => 5107,
			'status'     => 'wc-completed',
			'qty'        => 1,
		);

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados[0]['groups'] );
		self::assertSame( self::TERM_BERSERK, $this->email->enviados[0]['groups'][0]['term_id'] );
	}

	/** @testdox [NEG] Un tomo despublicado antes del envío borra su fila y permite reavisar. */
	public function test_un_tomo_despublicado_antes_del_envio_borra_su_fila(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->estados[5107] = 'draft';

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertSame( array(), $this->queue_db->table, 'La fila se BORRA: el motivo es transitorio.' );
	}

	/** @testdox Una corrida caída a medias reanuda sin reenviar. */
	public function test_corrida_caida_a_medias_reanuda_sin_reenviar(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 11, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();
		self::assertCount( 2, $this->email->enviados );

		// Segunda corrida: los servidos tienen su fila `sent` y su tope fresco.
		$this->runner()->run();

		self::assertCount( 2, $this->email->enviados, 'Nadie recibe el mismo tomo dos veces.' );
	}

	/** @testdox [NEG] Un fallo de envío devuelve las filas a pending y NO sella el tope. */
	public function test_fallo_de_envio_devuelve_las_filas_a_pending_y_no_sella_el_tope(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->acepta = false;

		$this->runner()->run();

		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertArrayNotHasKey( 10, $this->ultimo_envio, 'Si el correo no salió, el cliente no gastó su semana.' );
	}

	/** @testdox [NEG] Una excepción del mailer devuelve las filas a pending. */
	public function test_una_excepcion_del_mailer_devuelve_las_filas_a_pending(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->revienta = true;

		$this->runner()->run();

		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertArrayNotHasKey( 10, $this->ultimo_envio );
	}

	/**
	 * EL CANDADO DEL ORDEN ARMAR→RECLAMAR. Si se reclamara primero, una
	 * excepción armando el contenido dejaría las filas `sent` sin correo, y
	 * `uniq_event` prohíbe reencolarlas: ese aviso se perdería para siempre.
	 *
	 * @testdox Una excepción armando el correo deja las filas en PENDING.
	 */
	public function test_una_excepcion_armando_el_correo_deja_las_filas_en_pending(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->error_al_armar = 'la política de cortes se cayó';

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_SENT ) );
	}

	/** @testdox [NEG] El email deshabilitado en WooCommerce no quema el lote a sent. */
	public function test_email_deshabilitado_en_wc_no_quema_el_lote_a_sent(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->habilitado = false;

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
	}

	/**
	 * EL DEFECTO QUE CIERRA (S-eb98936e, 2026-09-19): el `return` de arriba era
	 * correcto en no quemar filas, pero salía SIN dejar traza y SIN re-agendar
	 * la corrida. La cadena del digest son acciones de una sola vez que se
	 * encadenan entre sí — si esta corrida no agenda la siguiente, la cola se
	 * congela hasta que se publique otro tomo, sin que nadie en el panel o el
	 * log lo note.
	 *
	 * @testdox [NEG] El email deshabilitado en WooCommerce deja traza y re-agenda la corrida.
	 */
	public function test_email_deshabilitado_en_wc_deja_traza_y_reagenda_la_corrida(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->habilitado = false;

		$this->runner()->run();

		self::assertNotSame(
			array(),
			$this->logs,
			'Sin traza, apagar el correo en WC es indiagnosticable: el digest se congela en silencio.'
		);
		self::assertSame(
			array( SeriesFollow::HOOK_DIGEST ),
			array_column( $this->scheduled, 'hook' ),
			'Sin re-agendar, nada vuelve a mirar la cola hasta el próximo tomo publicado.'
		);
		self::assertSame(
			array(
				'mailer_try' => 0,
				'email_try'  => 1,
			),
			$this->scheduled[0]['args']
		);
	}

	/**
	 * Revisión de Fable 5.1 (2026-09-19). La prueba del techo de más abajo llama
	 * `run( array( 'email_try' => 4 ) )`, que NO es como Action Scheduler ejecuta
	 * una acción: hace `do_action_ref_array( $hook, array_values( $args ) )`, así
	 * que las claves se pierden y cada valor entra por su POSICIÓN. Con los args
	 * `array( 'email_try' => n )`, `n` llegaba como `$mailer_try` y `$email_try`
	 * volvía a 0 en cada vuelta: la cadena no tenía techo —un reintento cada 15
	 * minutos para siempre— y el aviso del log se repetía en cada uno. La otra
	 * prueba pasaba con el defecto dentro; ésta ejecuta la cadena como la ejecuta
	 * la cola.
	 *
	 * @testdox Ejecutada como la ejecuta Action Scheduler, la cadena por correo deshabilitado TERMINA y avisa una sola vez.
	 */
	public function test_la_cadena_por_correo_deshabilitado_termina_ejecutada_como_la_cola(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->habilitado = false;

		$runner = $this->runner();
		$runner->run();

		$vueltas = 0;
		while ( array() !== $this->scheduled && $vueltas < 20 ) {
			$accion = array_shift( $this->scheduled );
			$runner->run( ...array_values( $accion['args'] ) ); // Así la llama la cola.
			++$vueltas;
		}

		self::assertSame( 4, $vueltas, 'Cuatro reintentos (≈ una hora) y se suelta: sin techo daba 20 y seguía.' );
		self::assertCount( 1, $this->logs, 'El aviso sale una vez por cadena, no en cada reintento.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ), 'Los avisos NO se pierden: siguen pendientes.' );
	}

	/**
	 * Mismo techo que ya paga `BackInStock\NotificationDispatcher` para el
	 * mismo caso: sin él, la corrida rebotaría cada 15 minutos para siempre
	 * mientras el correo siga apagado en WC.
	 *
	 * @testdox Agotado el techo de reintentos por email deshabilitado, el digest deja de re-agendarse.
	 */
	public function test_el_reintento_por_email_deshabilitado_tiene_techo(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->email->habilitado = false;

		$this->runner()->run( array( 'email_try' => 4 ) );

		self::assertSame( array(), $this->scheduled, 'Con el techo agotado no se agenda nada más.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ), 'Los avisos NO se pierden: siguen pendientes.' );
	}

	/** @testdox [NEG] El interruptor global apagado no marca ni envía, y reencender retoma. */
	public function test_interruptor_global_apagado_no_marca_ni_envia_y_reencender_retoma(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->flag = 'no';

		$runner = $this->runner();
		$runner->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );

		// El re-arranque cuelga del evento REAL —guardar el ajuste— y no de una
		// acción rebotando cada quince minutos para siempre.
		$this->flag = 'yes';
		$runner->on_setting_saved( 'no', 'yes' );

		self::assertSame(
			array( SeriesFollow::HOOK_DIGEST ),
			array_column( $this->scheduled, 'hook' )
		);

		$runner->run();
		self::assertCount( 1, $this->email->enviados );
	}

	/** @testdox Guardar el ajuste sin nada en cola no agenda nada. */
	public function test_guardar_el_ajuste_sin_cola_no_agenda_nada(): void {
		$this->runner()->on_setting_saved( 'no', 'yes' );

		self::assertSame( array(), $this->scheduled );
	}

	/**
	 * CANDADO DE FUENTE (S-eb98936e, 2026-09-19): el docblock de
	 * `reschedule_disabled_email()` decía que, agotado el techo de reintentos,
	 * la cola quedaba «esperando el próximo tomo publicado o que alguien
	 * reencienda el correo a mano». Eso es falso — reactivar el correo en
	 * WooCommerce → Emails no engancha ningún `update_option_*` ni
	 * `woocommerce_settings_saved`, así que ese clic no reagenda nada. Sólo el
	 * interruptor PROPIO de Akibara (`OPTION_ENABLED`, con su
	 * `on_setting_saved()` en `register()`) lo hace. Montar el guardado real de
	 * un `WC_Email` en WooCommerce → Ajustes → Emails sólo para probar una
	 * ausencia sería desproporcionado; el candado de fuente confirma las dos
	 * mitades del hecho que el comentario corregido afirma: (a) el texto
	 * engañoso no vuelve, y (b) el único hook de reanudación sigue siendo el
	 * de `OPTION_ENABLED` — nada ata la reanudación al ajuste nativo de WC.
	 *
	 * @testdox El docblock no promete que reactivar el correo en WC reanude la cola, y ningún hook lo hace.
	 */
	public function test_reactivar_el_correo_en_wc_no_tiene_ningun_hook_de_reanudacion(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$source      = (string) file_get_contents(
			$plugin_root . '/src/Modules/SeriesFollow/DigestRunner.php'
		);

		self::assertStringNotContainsString(
			'reencienda el correo a mano',
			$source,
			'El docblock volvió a sugerir que reactivar el correo de WC (sin hook) reanuda la cola.'
		);

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_root . '/src/Modules/SeriesFollow' )
		);
		$modulo_completo = '';
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$modulo_completo .= "\n" . (string) file_get_contents( $file->getPathname() );
			}
		}

		self::assertStringNotContainsString(
			'woocommerce_' . SeriesFollowEmail::EMAIL_ID . '_settings',
			$modulo_completo,
			'Nadie debe enganchar la reanudación de la cola al ajuste nativo de WC: ese ajuste no tiene traza ni retoma nada.'
		);
		self::assertStringContainsString(
			"update_option_' . SeriesFollow::OPTION_ENABLED",
			$source,
			'El único hook real de reanudación sigue siendo el interruptor propio de Akibara.'
		);
	}

	/** @testdox Corta a max_series_per_email y deja el resto pendiente. */
	public function test_corta_a_max_series_por_correo_y_deja_el_resto_pendiente(): void {
		$this->max_series = 2;
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );
		$this->encolar( 10, 7018, self::TERM_MONSTER, 18 );

		$this->runner()->run();

		self::assertCount( 2, $this->email->enviados[0]['groups'] );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertCount( 2, $this->filas( DigestQueue::STATUS_SENT ) );
	}

	/**
	 * @testdox Dos tomos de la MISMA serie: se anuncia el más nuevo y el otro NO queda como enviado.
	 *
	 * EL HUECO DE LA SUITE, encontrado midiendo en el espejo el 2026-09-06: todos
	 * los tests que metían varios tomos usaban un `term_id` distinto por tomo, o
	 * sea varias SERIES. El caso «dos filas con el mismo término» no existía, y es
	 * el que pasa de verdad en esta tienda: 49 tandas de dos o más tomos de la
	 * misma serie en siete días desde enero, y 381 filas que se marcaban enviadas
	 * sin nombrarse nunca.
	 *
	 * El colapso es correcto y se conserva —33 tomos de Fire Force el mismo jueves
	 * son un alta de catálogo, no 33 novedades—. Lo que estaba mal era la
	 * contabilidad: la tabla decía que al cliente se le avisó de un tomo cuyo
	 * número no aparecía en el correo.
	 */
	public function test_dos_tomos_de_la_misma_serie_no_queman_el_viejo(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 5106, self::TERM_ONE_PIECE, 106 );

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados[0]['groups'], 'Un solo bloque: es una serie.' );
		self::assertSame( 107, $this->email->enviados[0]['groups'][0]['volume'], 'Se anuncia el tomo más nuevo.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_SENT ), 'Sólo la fila que el correo nombró.' );
		self::assertCount(
			1,
			$this->filas( DigestQueue::STATUS_COALESCED ),
			'La del tomo 106 queda absorbida, no enviada: nunca se nombró.'
		);
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_PENDING ), 'Y no vuelve a salir: eso sería el spam que el colapso evita.' );
	}

	/**
	 * @testdox Si el correo falla, nada queda absorbido: vuelven todas a pendiente.
	 *
	 * El orden importa. Marcar la absorción antes del envío dejaría enterrado un
	 * tomo del que nunca salió aviso ninguno.
	 */
	public function test_un_envio_fallido_no_absorbe_nada(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 5106, self::TERM_ONE_PIECE, 106 );
		$this->email->acepta = false;

		$this->runner()->run();

		self::assertCount( 2, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_COALESCED ) );
	}

	/**
	 * @testdox Si lo único pendiente está bajo el tope, la próxima corrida NO es dentro de un minuto.
	 *
	 * Medido en producción el 2026-09-06: dos filas bloqueadas hasta el 12 de
	 * septiembre habrían disparado unas 8.600 corridas vacías, una por minuto
	 * durante seis días. Misma familia que el bombeo del buscador re-armándose
	 * contra deuda que todavía no madura.
	 */
	public function test_con_todos_bajo_el_tope_la_proxima_corrida_espera(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->ultimo_envio[10] = time() - ( 2 * DAY_IN_SECONDS );

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados, 'El tope bloquea el envío.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ), 'Y la fila sigue esperando.' );
		self::assertGreaterThan(
			HOUR_IN_SECONDS,
			$this->espera_agendada(),
			'Volver dentro de 60 s contra una fila que no puede salir hasta dentro de días es girar en vacío.'
		);
		self::assertLessThanOrEqual(
			DAY_IN_SECONDS,
			$this->espera_agendada(),
			'Y nunca más de un día: si el dueño baja el tope desde el panel, hay que replantearlo.'
		);
	}

	/**
	 * @testdox Si alguien sí recibió su aviso, se vuelve enseguida: puede haber más detrás.
	 */
	public function test_si_hubo_envio_la_proxima_corrida_es_inmediata(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 11, 6042, self::TERM_BERSERK, 42 );
		$this->ultimo_envio[11] = time() - ( 2 * DAY_IN_SECONDS );

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados, 'El cliente 10 sí recibe.' );
		self::assertLessThanOrEqual( 60, $this->espera_agendada() );
	}

	/**
	 * @testdox Una corrida EN CURSO no cuenta como «ya hay una agendada»: la cadena no se rompe sola.
	 *
	 * EL DEFECTO, medido en producción el 2026-09-06. `schedule_digest()`
	 * preguntaba con `as_next_scheduled_action()`, y esa función devuelve `true`
	 * en cuanto hay una acción del mismo hook **en curso** (Action Scheduler,
	 * `functions.php:362-366`: consulta STATUS_RUNNING antes que STATUS_PENDING y
	 * devuelve `true` sin mirar más). Al llamarla desde el final de `run()`, la
	 * acción en curso era la propia corrida: se contaba a sí misma y salía sin
	 * agendar nada.
	 *
	 * Consecuencia real: tres corridas del digest en toda la vida del módulo en
	 * producción, ninguna encadenada. Una fila retenida por el tope semanal se
	 * dormía hasta que alguien publicara otro tomo.
	 *
	 * El candado va sobre la fuente porque el doble no puede reproducir el estado
	 * «en curso» de Action Scheduler sin reimplementarlo entero.
	 */
	public function test_el_agendado_mira_solo_lo_pendiente(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesFollow/DigestRunner.php'
		);
		// Sin comentarios: el docblock que explica el arreglo nombra justo lo que
		// la prueba prohíbe, y lo tumbaría por la razón equivocada.
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertMatchesRegularExpression(
			"/'status'\s*=>\s*'pending'/",
			$fuente,
			'El agendado tiene que preguntar por lo PENDIENTE, no por lo que esté corriendo.'
		);
		self::assertDoesNotMatchRegularExpression(
			'/false !== as_next_scheduled_action\(\s*SeriesFollow::HOOK_DIGEST/',
			$fuente,
			'Con `as_next_scheduled_action` la corrida se cuenta a sí misma y rompe su cadena.'
		);
	}

	/**
	 * @testdox Una tanda de cuatro tomos nombra los cuatro, y enseña la ficha del más nuevo.
	 *
	 * Kingdom, 2026-05-20: cuatro tomos el mismo día. Esa tanda sí era una
	 * novedad de verdad y el correo enseñaba uno.
	 */
	public function test_una_tanda_de_cuatro_tomos_nombra_los_cuatro(): void {
		foreach ( array( 71, 72, 73, 74 ) as $tomo ) {
			$this->encolar( 10, 5000 + $tomo, self::TERM_ONE_PIECE, $tomo );
		}

		$this->runner()->run();

		$bloque = $this->email->enviados[0]['groups'][0];
		self::assertSame( 74, $bloque['volume'], 'La ficha es la del más nuevo.' );
		self::assertSame( array( 71, 72, 73, 74 ), $bloque['volumes'] );
		self::assertSame( 4, $bloque['volumes_count'] );
		self::assertSame( '71 al 74', $bloque['volumes_phrase'] );
	}

	/**
	 * @testdox El reparto de filas no cambió: una enviada, tres absorbidas, ninguna suelta.
	 *
	 * Vigila que resolver los tomos en la misma pasada que las absorbidas no dejó
	 * ninguna fila fuera del claim atómico. Ése es el camino que marcaba 381
	 * filas como enviadas sin nombrarlas.
	 */
	public function test_el_claim_y_la_absorcion_no_cambiaron(): void {
		foreach ( array( 71, 72, 73, 74 ) as $tomo ) {
			$this->encolar( 10, 5000 + $tomo, self::TERM_ONE_PIECE, $tomo );
		}

		$this->runner()->run();

		self::assertCount( 1, $this->filas( DigestQueue::STATUS_SENT ) );
		self::assertCount( 3, $this->filas( DigestQueue::STATUS_COALESCED ) );
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_PENDING ) );
	}

	/**
	 * @testdox Un solo tomo deja el bloque exactamente como hoy.
	 */
	public function test_un_solo_tomo_deja_el_bloque_igual_que_hoy(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();

		$bloque = $this->email->enviados[0]['groups'][0];
		self::assertSame( array( 107 ), $bloque['volumes'] );
		self::assertSame( 1, $bloque['volumes_count'] );
		self::assertSame( '107', $bloque['volumes_phrase'] );
	}

	/**
	 * @testdox Un envío fallido no absorbe la tanda entera: vuelven las cuatro.
	 */
	public function test_un_envio_fallido_no_absorbe_la_tanda(): void {
		foreach ( array( 71, 72, 73, 74 ) as $tomo ) {
			$this->encolar( 10, 5000 + $tomo, self::TERM_ONE_PIECE, $tomo );
		}
		$this->email->acepta = false;

		$this->runner()->run();

		self::assertCount( 4, $this->filas( DigestQueue::STATUS_PENDING ) );
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_COALESCED ) );
	}

	/**
	 * @testdox El bloque declara las tres claves nuevas, con esos nombres.
	 *
	 * Las plantillas las leen con `??`, así que un renombrado silencioso dejaría
	 * el correo mudo sin poner roja ninguna otra prueba.
	 */
	public function test_el_bloque_declara_las_tres_claves_nuevas(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();

		$bloque = $this->email->enviados[0]['groups'][0];
		foreach ( array( 'volumes', 'volumes_count', 'volumes_phrase' ) as $clave ) {
			self::assertArrayHasKey( $clave, $bloque );
		}
	}

	/** @testdox Las filas caducadas se barren al inicio de la corrida, sin cron aparte. */
	public function test_filas_pendientes_de_mas_de_60_dias_se_expiran_en_la_corrida(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$ids = array_keys( $this->queue_db->table );
		$this->queue_db->table[ $ids[0] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_EXPIRED ) );
	}

	/** @testdox Si quedan pendientes tras el lote, se agenda la corrida siguiente. */
	public function test_si_quedan_pendientes_se_agenda_la_corrida_siguiente(): void {
		$this->max_series = 1;
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		$this->encolar( 10, 6042, self::TERM_BERSERK, 42 );

		$this->runner()->run();

		self::assertSame(
			array( SeriesFollow::HOOK_DIGEST ),
			array_column( $this->scheduled, 'hook' )
		);
	}

	/** @testdox Sin nada pendiente la corrida no agenda una siguiente. */
	public function test_sin_pendientes_no_se_agenda_otra_corrida(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();

		self::assertSame( array(), $this->scheduled );
	}

	/** @testdox Cada bloque lleva el estado comercial del tomo y la línea del corte resuelta. */
	public function test_cada_bloque_lleva_estado_y_corte(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner()->run();

		$bloque = $this->email->enviados[0]['groups'][0];
		self::assertSame( 'Disponible', $bloque['availability'] );
		self::assertSame( '', $bloque['cutoff'], 'Un tomo Disponible no tiene corte que nombrar y NO inventa fecha.' );
		self::assertSame( 'Serie ' . self::TERM_ONE_PIECE, $bloque['series_name'] );
	}

	/**
	 * Con `WC_Email` sin cargar la corrida REVENTABA (`Class "WC_Email" not
	 * found`) y Action Scheduler no reintenta un fatal: el digest se perdía. Lo
	 * que se exige es que no se toque ni una fila y que la corrida vuelva a
	 * agendarse.
	 *
	 * @testdox Sin WC_Email cargable el digest se re-agenda y NO reclama filas.
	 */
	public function test_sin_mailer_cargable_se_reagenda_sin_reclamar_filas(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner( static fn (): bool => false )->run();

		self::assertSame( array(), $this->email->enviados, 'No puede enviarse nada sin mailer.' );
		self::assertSame( array(), $this->filas( DigestQueue::STATUS_SENT ), 'Ni una fila quemada a `sent`.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ), 'La fila sigue pendiente.' );
		self::assertSame(
			array( SeriesFollow::HOOK_DIGEST ),
			array_column( $this->scheduled, 'hook' ),
			'Tiene que quedar una corrida re-agendada.'
		);
		self::assertSame( array( 'mailer_try' => 1 ), $this->scheduled[0]['args'] );
	}

	/**
	 * El módulo ya pagó una vez el precio de una acción re-agendándose «por si
	 * acaso» para siempre: 96 acciones al día por producto.
	 *
	 * @testdox Agotado el techo de reintentos, el digest deja de re-agendarse.
	 */
	public function test_el_reintento_por_mailer_caido_tiene_techo(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner( static fn (): bool => false )->run( 4 );

		self::assertSame( array(), $this->scheduled, 'Con el techo agotado no se agenda nada más.' );
		self::assertCount( 1, $this->filas( DigestQueue::STATUS_PENDING ), 'Los avisos NO se pierden: siguen pendientes.' );
	}

	/**
	 * Action Scheduler entrega los args posicionalmente, pero una invocación
	 * manual (`do_action`) puede pasar el array entero.
	 *
	 * @testdox El contador de reintentos se lee también si llega envuelto en array.
	 */
	public function test_el_contador_acepta_la_forma_array_de_action_scheduler(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );

		$this->runner( static fn (): bool => false )->run( array( 'mailer_try' => 4 ) );

		self::assertSame( array(), $this->scheduled );
	}

	/**
	 * @testdox [NEG] Un tomo que se agota antes del envío borra su fila para poder reavisar cuando vuelva.
	 */
	public function test_un_tomo_agotado_antes_del_envio_borra_su_fila(): void {
		$this->encolar( 10, 5107, self::TERM_ONE_PIECE, 107 );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( int $id, string $key = '', bool $single = false ): string => '_stock_status' === $key ? 'outofstock' : ''
		);

		$this->runner()->run();

		self::assertSame( array(), $this->email->enviados );
		self::assertSame( array(), $this->queue_db->table );
	}

	/**
	 * @testdox Un tomo menor al máximo comprado del cliente se marca con is_gap = true y lleva timeframe_copy.
	 */
	public function test_un_tomo_menor_al_maximo_comprado_marca_is_gap_y_timeframe_copy(): void {
		$this->orders_db->lineas[] = array(
			'user_id'    => 10,
			'product_id' => 5101,
			'status'     => 'wc-completed',
			'qty'        => 1,
		);
		$this->orders_db->lineas[] = array(
			'user_id'    => 10,
			'product_id' => 5110,
			'status'     => 'wc-completed',
			'qty'        => 1,
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_terms' )->alias(
			static fn () => array(
				(object) array(
					'term_id' => self::TERM_ONE_PIECE,
					'name'    => 'One Piece',
				),
			)
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( int $id ): string => 5101 === $id ? 'One Piece 1' : ( 5110 === $id ? 'One Piece 10' : 'One Piece 5' )
		);

		$this->encolar( 10, 5105, self::TERM_ONE_PIECE, 5 );

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados );
		$bloque = $this->email->enviados[0]['groups'][0];
		self::assertTrue( $bloque['is_gap'] );
		self::assertSame( 'disponible', $bloque['availability_state'] );
		self::assertStringContainsString( '24 a 48 h', $bloque['timeframe_copy'] );
	}
}
