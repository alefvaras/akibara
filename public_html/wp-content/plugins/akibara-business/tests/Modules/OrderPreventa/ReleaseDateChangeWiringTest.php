<?php
/**
 * EL ENGANCHE del aviso de cambio de fecha — la prueba que faltaba.
 *
 * Hasta hoy el camino «el dueño cambia la fecha de un producto → al cliente le
 * llega el correo» estaba cubierto en TRES ISLAS que no se tocan entre sí:
 *
 *   1. `ProductDataPanelSaveTest` — el productor dispara
 *      `akb_eta_release_date_changed`… con `do_action` STUBEADO.
 *   2. `ReleaseDateChangeClassifierTest` — `classify()` por reflexión: función
 *      pura, sin pedidos, sin hooks.
 *   3. `ReminderEmailNotesTest` — llama `trigger_date_changed()` A MANO sobre
 *      un doble. Que el método funcione no prueba que alguien lo llame.
 *
 * Entre isla e isla no había NADA. Medido por mutación el 2026-09-02 contra la
 * suite entera (2369 pruebas), estas OCHO regresiones la dejaban en VERDE:
 *
 *   1. Borrar el `do_action( 'akb_order_preventa_date_changed' )` del listener.
 *   2. Borrar el `do_action( 'akb_order_preventa_date_announced' )`.
 *   3. Quitar el alta del hook en `woocommerce_email_actions`.
 *   4. Quitar también la del T-7 (que en producción SÍ funciona).
 *   5. Borrar el `add_action( '…_notification' )` del email.
 *   6. Vaciar el `register()` del listener.
 *   7. Envenenar `affected_order_query_args()` (conjunto vacío).
 *   8. Reordenar las claves de los args de Action Scheduler.
 *
 * El control confirma que la medición vale: mutar el CUERPO de
 * `trigger_date_changed()` sí rompe 3 pruebas. Lo que nadie miraba era el
 * enganche.
 *
 * Esta prueba cierra los cuatro eslabones y falla con las ocho. Fija el CAMINO,
 * no los literales: el nombre del hook se lee de lo que el listener disparó de
 * verdad y se persigue por el resto de la cadena.
 *
 * Por qué faltaba: Brain Monkey NO ejecuta los callbacks que `add_action`
 * registra (`inc/wp-hook-functions.php` sólo los apunta), así que «disparo el
 * hook y miro si sale el correo» no se puede escribir con este arnés. Lo que sí
 * se puede —y es lo que hace esta prueba— es afirmar cada eslabón por separado
 * y encadenarlos por el nombre que el propio código emite.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReleaseDateChangeListener;
use Akibara\Business\Modules\OrderPreventa\ReminderEmail;
use Akibara\Business\Modules\OrderPreventa\Settings;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class ReleaseDateChangeWiringTest extends TestCase {

	private const PRODUCT_ID = 4242;

	private WC_Order $order;

	/** Fechas RELATIVAS al reloj: con literales, la prueba caducaba el día en que la fecha «futura» pasaba. */
	private string $vieja = '';

	private string $nueva = '';

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) );

		$this->vieja = gmdate( 'Y-m-d', time() + 400 * DAY_IN_SECONDS );
		$this->nueva = gmdate( 'Y-m-d', time() + 430 * DAY_IN_SECONDS );

		$this->options = array(
			Settings::OPT_DATE_CHANGE_THRESHOLD  => 7,
			Settings::OPT_DATE_CHANGE_NOTIFY_ADV => 'no',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, ?int $ts = null ): string => gmdate( $format, (int) $ts )
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( string $path ): string => rtrim( $path, '/\\' ) . '/'
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		// Desde 1.114.15 el recalculador pregunta a `Availability::is_preventa()`
		// si la fecha de salida sigue vigente (canal get_post_meta + zona). Con
		// fecha futura el camino es el de siempre: calculate().
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		$nueva = $this->nueva;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key = '', bool $single = false ) use ( $nueva ) {
				if ( self::PRODUCT_ID !== $id ) {
					return '';
				}
				return '_akb_eta_type' === $key ? 'release_date' : ( '_akb_eta_release_date' === $key ? $nueva : '' );
			}
		);

		$this->order = $this->eligible_order();

		// La consulta REAL del listener pasa por aquí. Devolver el pedido sólo
		// si los args son los que WooCommerce entiende: así, envenenar
		// `affected_order_query_args()` deja el conjunto vacío y la prueba cae.
		$order = $this->order;
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( $order ) {
				$page = (object) array(
					'orders'        => array(),
					'max_num_pages' => 1,
				);
				// La consulta del lote pide los estados de RECÁLCULO (espera +
				// en espera de pago); el doble devuelve el pedido sólo si el
				// suyo está entre ellos, como haría WooCommerce.
				$estados = (array) ( $args['status'] ?? array() );
				if ( ! in_array( $order->akb_status, $estados, true ) ) {
					return $page;
				}
				// La consulta usa `meta_query` (no `meta_key`/`meta_value`) desde
				// 1.114.8: antes exigía el literal 'no' y dejaba fuera a los
				// pedidos SIN la meta, que el guard de `process_order()` sí
				// acepta. Ese desfase hacía invisible a un pedido legítimo sin
				// dejar rastro, y costó una investigación entera: por eso el
				// doble comprueba la forma NUEVA y el conjunto se vacía si
				// alguien vuelve a la vieja.
				$mq = $args['meta_query'] ?? array();
				if ( ! is_array( $mq ) || 'OR' !== ( $mq['relation'] ?? '' ) ) {
					return $page;
				}
				$claves = array();
				foreach ( $mq as $k => $clausula ) {
					if ( 'relation' === $k || ! is_array( $clausula ) ) {
						continue;
					}
					$claves[] = ( $clausula['key'] ?? '' ) . ':' . ( $clausula['compare'] ?? '' );
				}
				$esperadas = array(
					MetaKeys::ORDER_READY_TO_SHIP . ':!=',
					MetaKeys::ORDER_READY_TO_SHIP . ':NOT EXISTS',
				);
				sort( $claves );
				sort( $esperadas );
				if ( $claves !== $esperadas ) {
					return $page;
				}
				$page->orders = array( $order );
				return $page;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Pedido que pasa TODOS los guards de `process_order()`. */
	private function eligible_order(): WC_Order {
		$product         = new \WC_Product(
			'onbackorder',
			array( DateCalculator::META_TYPE => 'release_date' )
		);
		// Con el id real: `Availability` lee `get_post_meta( id )`, y un doble
		// con id 0 hacía que el recalculador tomara la rama «fecha vencida» sin
		// que ningún assert lo notara.
		$product->akb_id = self::PRODUCT_ID;

		$item                 = new WC_Order_Item_Product();
		$item->akb_id         = 1;
		$item->akb_quantity   = 1;
		$item->akb_product_id = self::PRODUCT_ID;
		$item->akb_product    = $product;
		$item->akb_meta       = array(
			MetaKeys::ITEM_WAS_PREVENTA    => 'yes',
			MetaKeys::ITEM_MAX_AT_PURCHASE => 1790000000,
		);

		$order             = new WC_Order();
		$order->akb_id     = 31337;
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );
		$order->akb_meta   = array(
			MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
			MetaKeys::ORDER_READY_TO_SHIP => 'no',
			MetaKeys::ORDER_SUMMARY_MAX   => 1790000000,
		);

		return $order;
	}

	/** Arribo que devuelve el calculador vivo (fecha futura → `calculate()`). */
	public const ARRIBO_NUEVO = 1796000000;

	private function listener(): ReleaseDateChangeListener {
		$vivo = new class() extends NullEtaProvider {
			public function calculate( \WC_Product $product ): ?EtaResult {
				return EtaResult::range( self::ARRIBO_NUEVO_MIN, ReleaseDateChangeWiringTest::ARRIBO_NUEVO );
			}
			private const ARRIBO_NUEVO_MIN = 1795000000;
		};
		return new ReleaseDateChangeListener( new SummaryRecalculator( $vivo ) );
	}

	// -----------------------------------------------------------------------
	// Eslabón 1 — el listener escucha al productor y a su propio worker.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El listener se suscribe al aviso de cambio de fecha del producto.
	 */
	public function test_the_listener_subscribes_to_the_product_event(): void {
		$listener = $this->listener();
		$listener->register();

		self::assertNotFalse(
			Actions\has( 'akb_eta_release_date_changed', array( $listener, 'on_release_date_changed' ) ),
			'Nadie escucha el cambio de fecha: el aviso al cliente muere en el productor.'
		);
	}

	/**
	 * @testdox El worker diferido de Action Scheduler tiene quien lo atienda.
	 */
	public function test_the_deferred_worker_is_wired(): void {
		$listener = $this->listener();
		$listener->register();

		self::assertNotFalse(
			Actions\has( 'akb_order_preventa_release_date_changed_batch', array( $listener, 'run_release_date_change' ) ),
			'El job se encola y nadie lo ejecuta: la pasada nunca corre.'
		);
	}

	/**
	 * El viaje de ida y vuelta por Action Scheduler. El listener encola args
	 * ASOCIATIVOS y el worker los recibe POSICIONALES: AS los re-expande con
	 * `array_values()`, así que reordenar las claves del array manda la fecha
	 * vieja donde va el id del producto — sin error, sin correo. Esta prueba
	 * hace el mismo round-trip y persigue el resultado hasta el hook del cliente.
	 *
	 * @testdox El trabajo encolado, re-expandido como lo hace Action Scheduler, avisa al cliente.
	 */
	public function test_the_scheduled_job_replays_into_the_customer_hook(): void {
		$scheduled = array();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $timestamp, $hook, $args = array(), $group = '' ) use ( &$scheduled ): int {
				unset( $timestamp, $group );
				$scheduled[] = array( (string) $hook, (array) $args );
				return 1;
			}
		);

		$listener = $this->listener();
		$listener->on_release_date_changed( self::PRODUCT_ID, $this->vieja, $this->nueva );

		self::assertCount( 1, $scheduled, 'El cambio de fecha no encoló ningún trabajo.' );
		self::assertSame( 'akb_order_preventa_release_date_changed_batch', $scheduled[0][0] );

		// Exactamente lo que hace Action Scheduler al ejecutar la acción.
		$listener->run_release_date_change( ...array_values( $scheduled[0][1] ) );

		self::assertGreaterThan(
			0,
			Actions\did( 'akb_order_preventa_date_changed' ),
			'El trabajo encolado corrió y el cliente no se enteró.'
		);
		self::assertSame(
			$this->vieja . '__' . $this->nueva,
			(string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS )
		);
	}

	// -----------------------------------------------------------------------
	// Eslabón 2 — la pasada real llega hasta el hook del cliente.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Una postergación real dispara el aviso al cliente y deja la firma.
	 */
	public function test_a_real_postponement_reaches_the_customer_hook(): void {
		$this->listener()->run_release_date_change( self::PRODUCT_ID, $this->vieja, $this->nueva );

		self::assertGreaterThan(
			0,
			Actions\did( 'akb_order_preventa_date_changed' ),
			'La pasada terminó sin avisar al cliente de la postergación.'
		);
		self::assertSame(
			$this->vieja . '__' . $this->nueva,
			(string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS ),
			'Sin firma persistida no hay traza del evento ni dedup.'
		);
		self::assertSame(
			self::ARRIBO_NUEVO,
			(int) $this->order->get_meta( MetaKeys::ORDER_SUMMARY_MAX ),
			'La postergación tiene que llegar al arribo que ve el cliente: el camino calculate() del recalculador.'
		);
		self::assertNotSame(
			array(),
			array_values(
				array_filter(
					$this->order->akb_notes,
					static fn( string $note ): bool => str_contains( $note, 'Postergación registrada' )
				)
			),
			'El pedido no registró el evento.'
		);
	}

	/**
	 * @testdox A un pedido en espera de pago se le recalcula la fecha, no se le escribe, y queda dicho por qué.
	 */
	public function test_an_unpaid_order_is_recomputed_but_not_emailed(): void {
		$this->order->akb_status = 'on-hold';
		$this->listener()->run_release_date_change( self::PRODUCT_ID, $this->vieja, $this->nueva );
		self::assertSame( 0, Actions\did( 'akb_order_preventa_date_changed' ), 'A quien no ha pagado no se le manda el aviso.' );
		self::assertSame( $this->vieja . '__' . $this->nueva, (string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS ), 'El cambio queda registrado igual.' );
		self::assertSame( self::ARRIBO_NUEVO, (int) $this->order->get_meta( MetaKeys::ORDER_SUMMARY_MAX ), 'La fecha que verá al pagar es la nueva.' );
		self::assertNotEmpty( array_filter( $this->order->akb_notes, static fn( string $n ): bool => str_contains( $n, 'espera de pago' ) ), 'Sin nota, el dueño no sabe por qué no salió el correo.' );
	}

	/**
	 * @testdox A quien ya tiene el tomo en bodega no se le avisa de la fecha de la editorial.
	 */
	public function test_a_line_that_already_arrived_is_not_notified(): void {
		// El mismo pedido, pero el tomo ya está en stock: la línea no está
		// pendiente. Mandarle «nueva fecha de llegada» sería mentirle.
		$this->order->akb_items[0]->akb_product = new \WC_Product( 'instock', array( DateCalculator::META_TYPE => 'release_date' ) );
		$this->order->akb_items[0]->akb_meta[ MetaKeys::ITEM_ARRIVED ]     = 'yes';
		$this->order->akb_items[0]->akb_meta[ MetaKeys::ITEM_ARRIVED_QTY ] = '1';

		$this->listener()->run_release_date_change( self::PRODUCT_ID, $this->vieja, $this->nueva );

		self::assertSame( 0, Actions\did( 'akb_order_preventa_date_changed' ) );
		self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS ) );
	}

	/**
	 * Un pedido ajeno al producto no puede recibir el aviso.
	 *
	 * @testdox Un pedido sin ese producto no recibe el aviso.
	 */
	public function test_an_unrelated_order_is_left_alone(): void {
		$this->listener()->run_release_date_change( self::PRODUCT_ID + 1, $this->vieja, $this->nueva );

		self::assertSame( 0, Actions\did( 'akb_order_preventa_date_changed' ) );
		self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS ) );
	}

	// -----------------------------------------------------------------------
	// Eslabón 3 — WooCommerce tiene que re-emitir ese hook con `_notification`.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El hook que dispara el listener está declarado en woocommerce_email_actions.
	 */
	public function test_woocommerce_is_told_to_re_emit_the_hook(): void {
		self::assertContains(
			'akb_order_preventa_date_changed',
			$this->registered_email_actions(),
			'WooCommerce no re-emite el hook: el mailer nunca se entera y no arranca en cron.'
		);
	}

	// -----------------------------------------------------------------------
	// Eslabón 4 — el email escucha el hook re-emitido.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El email escucha el hook re-emitido por WooCommerce.
	 */
	public function test_the_email_listens_on_the_re_emitted_hook(): void {
		$email = new ReminderEmailSpy();

		self::assertNotFalse(
			Actions\has( 'akb_order_preventa_date_changed_notification', array( $email, 'trigger_date_changed' ) ),
			'El email no escucha: WooCommerce re-emite al vacío.'
		);
	}

	// -----------------------------------------------------------------------
	// La cadena entera, sin literales sueltos.
	// -----------------------------------------------------------------------

	/**
	 * Persigue el nombre del hook DESDE lo que el listener disparó de verdad:
	 * si mañana se renombra, la prueba lo sigue; si se rompe un eslabón, cae.
	 *
	 * @testdox La cadena cambio de fecha → cliente no tiene ningún eslabón suelto.
	 */
	public function test_the_chain_has_no_broken_link(): void {
		$fired = array();
		Actions\expectDone( 'akb_order_preventa_date_changed' )
			->whenHappen(
				static function ( $order_id, $product_id, $old, $new ) use ( &$fired ): void {
					$fired[] = array( (int) $order_id, (int) $product_id, (string) $old, (string) $new );
				}
			);

		$this->listener()->run_release_date_change( self::PRODUCT_ID, $this->vieja, $this->nueva );

		self::assertSame(
			array( array( 31337, self::PRODUCT_ID, $this->vieja, $this->nueva ) ),
			$fired,
			'El listener no emitió el evento con el pedido y las fechas correctas.'
		);

		$hook = 'akb_order_preventa_date_changed';

		self::assertContains( $hook, $this->registered_email_actions() );

		$email = new ReminderEmailSpy();
		self::assertNotFalse( Actions\has( $hook . '_notification', array( $email, 'trigger_date_changed' ) ) );
	}

	/**
	 * Lo que `OrderPreventa::register()` le declara a WooCommerce, obtenido
	 * ejecutando el callback REAL que se registró en el filtro.
	 *
	 * @return array<int, string>
	 */
	private function registered_email_actions(): array {
		$callback = null;
		Filters\expectAdded( 'woocommerce_email_actions' )
			->whenHappen(
				static function ( $cb ) use ( &$callback ): void {
					$callback = $cb;
				}
			);

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );

		( new \Akibara\Business\Modules\OrderPreventa\OrderPreventa( new NullEtaProvider() ) )->register();

		self::assertIsCallable( $callback, 'Nadie registró el filtro woocommerce_email_actions.' );

		return array_values( array_map( 'strval', (array) $callback( array() ) ) );
	}
}
