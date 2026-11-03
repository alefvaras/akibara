<?php
/**
 * Contrato de seguridad ante excepciones del despacho de "preventa lista".
 *
 * WooCommerce invoca el callback del email SIN `try/catch`
 * (`class-wc-email.php:1228-1255`), así que cualquier `Throwable` que escape de
 * la generación del contenido, de un filtro o del transporte sale del `trigger()`
 * tal cual. Hoy eso deja dos rastros: el locale del cliente queda ABIERTO en el
 * worker (`setup_locale()` en `:130`, `restore_locale()` sólo en el camino
 * normal `:138`) y el marker ya está persistido (`:127-128`), de modo que el
 * retry que agenda `OrderPreventa::maybe_retry_failed_ready_email_dispatch()`
 * entra y sale como no-op — la orden queda sin entrega y sin reintento
 * [auditoría 2026-08-05].
 *
 * El framing que fijan estas pruebas es **at-most-once**: nunca se borra el
 * marker por un `Throwable`, porque una excepción POSTERIOR a que el transporte
 * aceptó el mensaje es ambigua y borrar el marker ahí duplicaría la entrega. Lo
 * que sí se exige es que el fallo sea visible y que el worker quede limpio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipEmailDispatchLockException;
use Akibara\Business\Modules\OrderPreventa\SendFailureRearm;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

require_once __DIR__ . '/ReadyToShipEmailSpy.php';

final class ReadyToShipEmailDispatchTest extends TestCase {

	/** Orden bajo prueba, servida por `wc_get_order()`. */
	private WC_Order $order;

	/** Doble de $wpdb: sólo GET_LOCK / RELEASE_LOCK. */
	private object $wpdb;

	/**
	 * Filtros retirados durante el caso — `remove_filter()` no tiene registro
	 * propio en Brain Monkey, así que se anota acá.
	 *
	 * @var array<int, string>
	 */
	public static array $removed_filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) );

		WC_Email::akb_reset_locale_counters();
		self::$removed_filters = array();

		// El envío deja traza por `Utilities\Log`, que sólo escribe si
		// `wc_get_logger()` existe. Estos casos pasaban por la puerta de atrás:
		// no lo declaraban y `function_exists()` respondía false… salvo cuando
		// OTRO test de la suite había definido la función antes, y entonces
		// reventaban. Se declara acá para que el resultado no dependa del orden.
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/**
				 * @param array<string, mixed> $context
				 */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);

		Functions\when( 'remove_filter' )->alias(
			static function ( string $hook ): bool {
				ReadyToShipEmailDispatchTest::$removed_filters[] = $hook;
				return true;
			}
		);

		$this->order = $this->eligible_order();

		$this->wpdb = new class() {
			/** @var array<int, string> */
			public array $locks = array();

			public bool $lock_available = true;

			public string $prefix = 'wp_';

			public string $postmeta = 'wp_postmeta';

			public string $last_error = '';

			/** Valor que devuelve la lectura del marker (null = ausente). */
			public ?string $marker_value = null;

			public function prepare( string $query, ...$args ): string {
				return $query . '|' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_var( string $query ) {
				if ( str_contains( $query, 'GET_LOCK' ) ) {
					$this->locks[] = 'acquire';
					return $this->lock_available ? '1' : '0';
				}
				if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
					$this->locks[] = 'release';
					return '1';
				}
				if ( str_contains( $query, 'meta_value' ) ) {
					return $this->marker_value;
				}
				return null;
			}
		};

		$GLOBALS['wpdb'] = $this->wpdb;

		$order = $this->order;
		Functions\when( 'wc_get_order' )->alias( static fn() => $order );
		Functions\when( 'trailingslashit' )->alias( static fn( string $path ): string => rtrim( $path, '/\\' ) . '/' );
		// `FulfillmentCapabilities` normaliza los estados con sanitize_key().
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Orden que pasa TODOS los guards de `can_trigger_for_order()`: estado
	 * elegible, marca de preventa, un tomo pendiente sin reembolsar, sin marker
	 * previo y con email de facturación.
	 */
	private function eligible_order(): WC_Order {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 7;
		$item->akb_quantity = 1;
		$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order              = new WC_Order();
		$order->akb_id      = 27188;
		$order->akb_status  = 'processing';
		$order->akb_items   = array( $item );
		$order->akb_meta    = array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' );

		return $order;
	}

	private function marker(): string {
		return (string) $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_SENT );
	}

	private function attempts(): int {
		return (int) $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS );
	}

	/** ¿Se liberó el lock que se tomó? */
	private function lock_balanced(): bool {
		$acquired = count( array_keys( $this->wpdb->locks, 'acquire', true ) );
		$released = count( array_keys( $this->wpdb->locks, 'release', true ) );

		return $acquired === $released;
	}

	// -----------------------------------------------------------------------
	// Camino feliz y fallo declarado del transporte (contrato vigente).
	// -----------------------------------------------------------------------

	/**
	 * @testdox El envío exitoso deja marker, locale equilibrado y lock liberado.
	 */
	public function test_successful_dispatch_is_clean(): void {
		$email = new ReadyToShipEmailSpy();

		$email->trigger( $this->order->get_id() );

		self::assertCount( 1, $email->akb_sent );
		self::assertNotSame( '', $this->marker() );
		self::assertTrue( WC_Email::akb_locale_is_balanced() );
		self::assertTrue( $this->lock_balanced() );
	}

	/**
	 * @testdox Un `false` del transporte re-arma el marker: ese camino NO cambia.
	 */
	public function test_transport_false_still_rearms(): void {
		$email                   = new ReadyToShipEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger( $this->order->get_id() );

		self::assertSame( '', $this->marker(), 'El marker debe quedar re-armado para el próximo intento.' );
		self::assertSame( 1, $this->attempts() );
		self::assertTrue( WC_Email::akb_locale_is_balanced() );
	}

	/**
	 * @testdox En el tope de intentos el marker se conserva y se avisa que no habrá más reintentos.
	 */
	public function test_transport_false_at_attempt_cap_stops(): void {
		$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS, SendFailureRearm::MAX_ATTEMPTS - 1 );

		$email                   = new ReadyToShipEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger( $this->order->get_id() );

		self::assertNotSame( '', $this->marker() );
		self::assertNotEmpty( $this->order->akb_notes );
	}

	// -----------------------------------------------------------------------
	// Excepción ANTES de que el transporte pudiera aceptar nada.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Si la generación del contenido lanza, el marker queda AUSENTE y el retry es seguro.
	 */
	public function test_exception_building_content_leaves_no_marker(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_throw_on_build = true;

		try {
			$email->trigger( $this->order->get_id() );
			self::fail( 'La excepción debe propagarse para que Action Scheduler registre el fallo.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'plantilla rota', $e->getMessage() );
		}

		self::assertCount( 0, $email->akb_sent );
		self::assertSame( '', $this->marker(), 'Nada se envió: el marker no debe bloquear el reintento.' );
	}

	/**
	 * @testdox Una excepción antes del envío no deja el locale del cliente abierto en el worker.
	 */
	public function test_exception_building_content_restores_locale(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_throw_on_build = true;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertTrue( WC_Email::akb_locale_is_balanced(), 'setup_locale() sin su restore_locale().' );
	}

	/**
	 * @testdox Una excepción antes del envío libera el lock de despacho.
	 */
	public function test_exception_building_content_releases_lock(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_throw_on_build = true;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertTrue( $this->lock_balanced() );
	}

	// -----------------------------------------------------------------------
	// Excepción DESPUÉS de que el transporte aceptó: entrega ambigua.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Si algo lanza después de que el transporte aceptó, el marker se CONSERVA (anti doble envío).
	 */
	public function test_exception_after_transport_keeps_marker(): void {
		$email                    = new ReadyToShipEmailSpy();
		$email->akb_throw_on_send = true;

		try {
			$email->trigger( $this->order->get_id() );
			self::fail( 'La excepción debe propagarse.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertCount( 1, $email->akb_sent, 'El transporte alcanzó a aceptar el mensaje.' );
		self::assertNotSame( '', $this->marker(), 'Borrarlo acá podría duplicar la entrega.' );
		self::assertSame( 0, $this->attempts(), 'No es un fallo declarado del transporte: no corresponde re-armar.' );
	}

	/**
	 * @testdox Una entrega ambigua deja nota durable en la orden: el operador tiene que verla.
	 */
	public function test_exception_after_transport_leaves_durable_note(): void {
		$email                    = new ReadyToShipEmailSpy();
		$email->akb_throw_on_send = true;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertNotEmpty( $this->order->akb_notes, 'Sin nota, la orden queda atascada en silencio.' );
		self::assertGreaterThan( 0, $this->order->akb_saves, 'La nota debe quedar persistida.' );
	}

	/**
	 * @testdox Una excepción después del transporte tampoco deja el locale abierto.
	 */
	public function test_exception_after_transport_restores_locale(): void {
		$email                    = new ReadyToShipEmailSpy();
		$email->akb_throw_on_send = true;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertTrue( WC_Email::akb_locale_is_balanced() );
		self::assertTrue( $this->lock_balanced() );
	}

	// -----------------------------------------------------------------------
	// Estado global que WooCommerce deja abierto si un callback lanza
	// [revisión adversarial Codex, P1-A/B/C/D].
	// -----------------------------------------------------------------------

	/**
	 * `setup_locale()` cambia el locale y engancha `plugin_locale` antes de
	 * terminar: si lanza a mitad de camino y la llamada vive FUERA del tramo
	 * protegido, el worker queda en el idioma del cliente.
	 *
	 * @testdox Una excepción DENTRO de setup_locale() igual restaura el locale y suelta el lock.
	 */
	public function test_exception_inside_locale_setup_is_contained(): void {
		$email                            = new ReadyToShipEmailSpy();
		$email->akb_throw_on_locale_setup = true;

		try {
			$email->trigger( $this->order->get_id() );
			self::fail( 'La excepción debe propagarse.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'plugin_locale reventó', $e->getMessage() );
		}

		self::assertTrue( WC_Email::akb_locale_is_balanced(), 'El locale quedó cambiado en el worker.' );
		self::assertTrue( $this->lock_balanced() );
		self::assertSame( '', $this->marker() );
		self::assertCount( 0, $email->akb_sent );
	}

	/**
	 * `WC_Email::get_content()` enciende `$sending` y sólo lo apaga
	 * `handle_multipart()` desde `phpmailer_init`. Un render que lanza lo deja
	 * en `true`, y ese handler está enganchado globalmente: le cambiaría el
	 * AltBody al próximo correo del request.
	 *
	 * @testdox Un render que lanza no deja `$sending` encendido.
	 */
	public function test_failed_render_does_not_leak_sending_flag(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_throw_on_build = true;

		self::assertFalse( $email->sending, 'Precondición: arranca apagado.' );

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertFalse( $email->sending, 'WC_Email::$sending quedó colgado tras la excepción.' );
	}

	/**
	 * @testdox El camino feliz tampoco deja `$sending` encendido.
	 */
	public function test_successful_dispatch_does_not_leak_sending_flag(): void {
		$email = new ReadyToShipEmailSpy();

		$email->trigger( $this->order->get_id() );

		self::assertCount( 1, $email->akb_sent );
		self::assertFalse( $email->sending );
	}

	/**
	 * `WC_Email::send()` instala `wp_mail_from`, `wp_mail_from_name` y
	 * `wp_mail_content_type`, y sólo los retira al retornar. Si el transporte
	 * lanza, todo correo posterior del request sale con el remitente de ESTE
	 * email.
	 *
	 * @testdox Tras una excepción del transporte los tres filtros de envío quedan retirados.
	 */
	public function test_transport_exception_removes_mail_filters(): void {
		$email                    = new ReadyToShipEmailSpy();
		$email->akb_throw_on_send = true;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		foreach ( array( 'wp_mail_from', 'wp_mail_from_name', 'wp_mail_content_type' ) as $filter ) {
			self::assertContains( $filter, self::$removed_filters, "El filtro {$filter} quedó instalado." );
		}
	}

	/**
	 * `WC_Order::save()` devuelve el ID aunque su data store haya capturado una
	 * excepción interna: "guardé" no es "quedó guardado". Enviar sin marker
	 * durable reabre el doble despacho AS‖cron.
	 *
	 * @testdox Si el marker no persistió, no se entrega nada y el fallo cuenta como pre-transporte.
	 */
	public function test_non_durable_marker_blocks_the_transport(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_marker_durable = false;

		try {
			$email->trigger( $this->order->get_id() );
			self::fail( 'Un marker no durable debe propagarse como fallo.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'no persistió', $e->getMessage() );
		}

		self::assertCount( 0, $email->akb_sent, 'No se puede entregar sin guard de idempotencia.' );
		self::assertSame( 1, $this->attempts(), 'Cuenta contra el presupuesto de reintentos.' );
		self::assertTrue( WC_Email::akb_locale_is_balanced() );
		self::assertTrue( $this->lock_balanced() );
	}

	/**
	 * El objeto sigue teniendo el marker en memoria cuando el camino
	 * pre-transporte guarda el contador de intentos: ese `save()` puede
	 * persistirlo y cerrar el reintento aunque el transporte nunca se invocó
	 * [Codex ronda 2, P1-D].
	 *
	 * @testdox Un marker no durable NO puede quedar guardado por el save del contador.
	 */
	public function test_non_durable_marker_is_removed_before_saving_attempts(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_marker_durable = false;

		try {
			$email->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		self::assertCount( 0, $email->akb_sent );
		self::assertSame( 1, $this->attempts() );
		self::assertSame( '', $this->marker(), 'El marker quedó persistido y bloquea el reintento.' );
	}

	/**
	 * @testdox Tras un fallo de durabilidad, el reintento entrega exactamente una vez.
	 */
	public function test_retry_after_durability_failure_delivers_exactly_once(): void {
		$first                     = new ReadyToShipEmailSpy();
		$first->akb_marker_durable = false;

		try {
			$first->trigger( $this->order->get_id() );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		// Segunda pasada del retry de Action Scheduler, ya sin el fallo de DB.
		$second = new ReadyToShipEmailSpy();
		$second->trigger( $this->order->get_id() );

		self::assertCount( 0, $first->akb_sent );
		self::assertCount( 1, $second->akb_sent, 'El retry debe poder entregar exactamente una vez.' );
		self::assertNotSame( '', $this->marker() );
	}

	/**
	 * @testdox Sin evidencia de persistencia, la verificación falla CERRADA.
	 */
	public function test_durability_check_fails_closed_without_evidence(): void {
		$email  = new ReadyToShipEmailSpy();
		$method = new \ReflectionMethod( ReadyToShipEmail::class, 'marker_is_durable' );
		$method->setAccessible( true );

		$real = new ReadyToShipEmail();

		// (a) la consulta devuelve null con error de conexión.
		$this->wpdb->marker_value = null;
		$this->wpdb->last_error   = 'MySQL server has gone away';
		self::assertFalse(
			$method->invoke( $real, $this->order->get_id(), 123 ),
			'Un error de lectura no es evidencia de que el marker esté guardado.'
		);

		// (b) no hay $wpdb en absoluto.
		unset( $GLOBALS['wpdb'] );
		self::assertFalse( $method->invoke( $real, $this->order->get_id(), 123 ) );

		$GLOBALS['wpdb'] = $this->wpdb;
		unset( $email );
	}

	/**
	 * @testdox Con el valor exacto en el almacenamiento, la verificación pasa.
	 */
	public function test_durability_check_accepts_the_exact_stored_value(): void {
		$method = new \ReflectionMethod( ReadyToShipEmail::class, 'marker_is_durable' );
		$method->setAccessible( true );

		$this->wpdb->marker_value = '4242';
		$this->wpdb->last_error   = '';

		$real = new ReadyToShipEmail();

		self::assertTrue( $method->invoke( $real, $this->order->get_id(), 4242 ) );
		self::assertFalse( $method->invoke( $real, $this->order->get_id(), 4243 ) );
	}

	/**
	 * @testdox La durabilidad se verifica ANTES de entregar, no después.
	 */
	public function test_durability_is_checked_before_the_transport(): void {
		$email = new ReadyToShipEmailSpy();

		$email->trigger( $this->order->get_id() );

		self::assertSame( array( $this->order->get_id() ), $email->akb_durability_checks );
		self::assertCount( 1, $email->akb_sent );
	}

	// -----------------------------------------------------------------------
	// El reintento seguro tiene que ser ACOTADO.
	// -----------------------------------------------------------------------

	/**
	 * Dejar el marker ausente habilita el reintento, y
	 * `ReadyEmailDispatcher::maybe_retry_failed_ready_email_dispatch()` re-agenda
	 * por cada acción fallida. Con una plantilla rota de forma permanente sería
	 * un reintento cada 305 s para siempre: lo corta este contador (y, para los
	 * fallos del worker, el suyo propio desde 1.116.6).
	 *
	 * @testdox Tres fallos antes de entregar nada agotan el presupuesto y dejan de reintentar.
	 */
	public function test_repeated_build_exceptions_stop_retrying(): void {
		$email                     = new ReadyToShipEmailSpy();
		$email->akb_throw_on_build = true;

		for ( $i = 0; $i < SendFailureRearm::MAX_ATTEMPTS; $i++ ) {
			try {
				$email->trigger( $this->order->get_id() );
			} catch ( RuntimeException $e ) {
				unset( $e );
			}
		}

		self::assertSame( SendFailureRearm::MAX_ATTEMPTS, $this->attempts() );
		self::assertNotEmpty( $this->order->akb_notes, 'El operador tiene que enterarse de que ya no se reintenta.' );

		// La pasada siguiente no debe siquiera intentarlo: sin este corte, el
		// retry de Action Scheduler queda en bucle.
		$email->akb_throw_on_build = false;
		$email->trigger( $this->order->get_id() );

		self::assertCount( 0, $email->akb_sent, 'Agotado el presupuesto, la recuperación es manual.' );
	}

	/**
	 * @testdox El reenvío manual limpia el contador, así que vuelve a intentarse.
	 */
	public function test_manual_resend_reopens_the_budget(): void {
		$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS, SendFailureRearm::MAX_ATTEMPTS );

		// Lo que hace `OrderPreventa::handle_resend_ready_email_action()`.
		$this->order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT );
		$this->order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS );

		$email = new ReadyToShipEmailSpy();
		$email->trigger( $this->order->get_id() );

		self::assertCount( 1, $email->akb_sent );
	}

	// -----------------------------------------------------------------------
	// Concurrencia: el segundo worker no puede tocar nada.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Con el lock tomado por otro worker no se escribe marker ni se abre locale.
	 */
	public function test_busy_lock_touches_nothing(): void {
		$this->wpdb->lock_available = false;

		$email = new ReadyToShipEmailSpy();

		$this->expectException( ReadyToShipEmailDispatchLockException::class );

		try {
			$email->trigger( $this->order->get_id() );
		} finally {
			self::assertSame( '', $this->marker() );
			self::assertCount( 0, $email->akb_sent );
			self::assertSame( 0, WC_Email::$akb_locale_setups );
		}
	}
}
