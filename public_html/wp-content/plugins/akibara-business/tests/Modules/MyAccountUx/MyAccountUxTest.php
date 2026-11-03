<?php
/**
 * Contracts for the mobile-first My Account navigation and dashboard.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\MyAccountUx;

use Akibara\Business\Modules\AccountCollection\Endpoint as CollectionEndpoint;
use Akibara\Business\Modules\AccountCollection\Renderer as CollectionRenderer;
use Akibara\Business\Modules\MyAccountUx\MyAccountUx;
use PHPUnit\Framework\TestCase;

final class MyAccountUxTest extends TestCase {

	/**
	 * El endpoint sólo necesita su renderer para construirse; el menú no lo usa.
	 */
	private function collection_endpoint(): CollectionEndpoint {
		return new CollectionEndpoint(
			new CollectionRenderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) )
		);
	}

	/**
	 * @testdox Collection is placed immediately after orders and logout remains last.
	 *
	 * El menú lo componen DOS filtros en prioridades distintas y hay que probarlos
	 * en ese orden: `MyAccountUx` reordena en la 20 y `AccountCollection\Endpoint`
	 * inserta `coleccion` en la 30. La versión anterior de este test le pasaba
	 * `coleccion` ya presente a la prioridad 20 —un estado que en el sitio no
	 * existe— y por eso daba verde sobre una clave que el reordenamiento no podía
	 * ver.
	 */
	public function test_collection_has_an_explicit_account_menu_position(): void {
		// Lo que WooCommerce entrega en la prioridad 20: sin `coleccion`.
		$items = ( new MyAccountUx() )->menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'favoritos'       => 'Favoritos',
				'edit-address'    => 'Addresses',
				'edit-account'    => 'Account details',
				'customer-logout' => 'Logout',
			)
		);

		self::assertSame( 'Resumen', $items['dashboard'] );
		self::assertSame( 'Direcciones', $items['edit-address'] );
		self::assertSame( 'Detalles', $items['edit-account'] );

		$items = $this->collection_endpoint()->menu_item( $items );

		self::assertSame(
			array( 'orders', 'coleccion', 'favoritos', 'edit-address', 'edit-account', 'dashboard', 'customer-logout' ),
			array_keys( $items ),
			'La colección debe ser una acción principal y nunca quedar después de Salir.'
		);
	}

	/** @testdox Rewards keeps the third slot once the collection is inserted. */
	public function test_rewards_keeps_its_visible_slot(): void {
		$items = ( new MyAccountUx() )->menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'favoritos'       => 'Favoritos',
				'rewards'         => 'Puntos',
				'edit-address'    => 'Addresses',
				'edit-account'    => 'Account details',
				'customer-logout' => 'Logout',
			)
		);
		$items = $this->collection_endpoint()->menu_item( $items );

		self::assertSame(
			array( 'orders', 'coleccion', 'rewards' ),
			array_slice( array_keys( $items ), 0, 3 ),
			'En 375 px sólo entran tres pestañas sin deslizar: el saldo de puntos tiene que ser una de ellas.'
		);
	}

	/** @testdox Unknown account items remain present instead of being discarded. */
	public function test_unknown_account_items_are_preserved(): void {
		$items = ( new MyAccountUx() )->menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'downloads'       => 'Downloads',
				'customer-logout' => 'Logout',
			)
		);

		self::assertArrayHasKey( 'downloads', $items );
		self::assertSame( 'Downloads', $items['downloads'] );
		self::assertSame(
			array( 'orders', 'downloads', 'dashboard', 'customer-logout' ),
			array_keys( $items ),
			'Los destinos extra deben mantenerse antes de Resumen y nunca después de Salir.'
		);
	}

	/**
	 * @testdox «Volver a comprar» no se ofrece en ningún estado del pedido.
	 *
	 * Decisión del dueño (2026-08-31): en una tienda de tomos seriados nadie
	 * recompra el mismo tomo. El filtro se conserva en sentido inverso porque
	 * WooCommerce puede volver a inyectar `order-again` por defecto.
	 *
	 * @dataProvider order_statuses
	 */
	public function test_the_reorder_action_is_never_offered( string $status ): void {
		$order = new class( $status ) {
			public function __construct( private string $status ) {}

			public function get_status(): string {
				return $this->status;
			}
		};

		$actions = ( new MyAccountUx() )->orders_actions(
			array(
				'view'       => array( 'url' => '/pedido/1', 'name' => 'Ver' ),
				'order-again' => array( 'url' => '/carrito/?order_again=1', 'name' => 'Volver a comprar' ),
			),
			$order
		);

		self::assertArrayNotHasKey( 'order-again', $actions );
		self::assertArrayHasKey( 'view', $actions, 'Las demás acciones del pedido siguen intactas.' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function order_statuses(): array {
		return array(
			'completado' => array( 'completed' ),
			'procesando' => array( 'processing' ),
			'pendiente'  => array( 'pending' ),
			'cancelado'  => array( 'cancelled' ),
		);
	}

	/** @testdox Dashboard exposes a direct collection card without user-controlled URLs. */
	public function test_dashboard_contains_the_collection_card(): void {
		$template = dirname( __DIR__, 3 ) . '/templates/myaccount/dashboard.php';
		self::assertFileExists( $template );

		$source = (string) file_get_contents( $template );
		self::assertStringContainsString( "wc_get_endpoint_url( 'coleccion' )", $source );
		self::assertStringContainsString( 'Colección', $source );
		self::assertStringContainsString( 'avance de cada edición', $source );
		self::assertStringContainsString( 'lo que te falta', $source );
	}

	/**
	 * La tarjeta convertía los puntos a pesos con `wc_price()`, que es uno a
	 * uno. El panel del programa permite cambiar «Pesos de descuento por punto
	 * canjeado» con un clic, y en cuanto dejara de valer 1, Mi cuenta prometía
	 * un descuento que la caja no iba a dar (hallazgo S-c94a30df, 2026-09-18).
	 *
	 * @testdox La tarjeta de puntos no convierte pesos por su cuenta.
	 */
	public function test_the_rewards_card_never_prices_points_itself(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/myaccount/dashboard.php' );

		self::assertStringNotContainsString(
			'wc_price( $akb_points )',
			$source,
			'El cambio lo fija el plugin de puntos, no esta plantilla.'
		);
		self::assertStringContainsString(
			"apply_filters( 'akb_rewards_points_label', '', \$akb_points )",
			$source,
			'Se pide por filtro, como el vocabulario del buscador.'
		);
		self::assertStringContainsString(
			"'' === \$akb_points_label",
			$source,
			'Sin respuesta, la tarjeta calla el monto en vez de inventarlo.'
		);
	}

	/**
	 * @testdox Should kill order-again at the ROOT so the detail template cannot print it either.
	 */
	public function test_order_again_is_dead_on_every_surface(): void {
		// QA visual 2026-09-01: el unset de las filas dejaba vivo el botón
		// del DETALLE del pedido (callejón sin salida). Con la lista de
		// estados vacía, el core no pinta el botón en ninguna plantilla ni
		// acepta el endpoint ?order_again=.
		// Patrón de la casa (BundleCouponEngineTest): stubs eval-guard que
		// registran a $GLOBALS — el test es autosuficiente en corrida
		// filtrada y convive con el contagio de la suite completa.
		if ( ! function_exists( 'add_filter' ) ) {
			eval(
				'namespace { function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					$GLOBALS["akibara_byp_filters"][] = array( $hook, $callback, $priority, $accepted_args );
					return true;
				} }'
			);
		}
		if ( ! function_exists( 'add_action' ) ) {
			eval(
				'namespace { function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					return true;
				} }'
			);
		}
		$GLOBALS['akibara_byp_filters'] = array();

		( new MyAccountUx() )->register();

		$registrado = false;
		if ( function_exists( 'has_filter' ) && false !== has_filter( 'woocommerce_valid_order_statuses_for_order_again', '__return_empty_array' ) ) {
			$registrado = true;
		}
		foreach ( (array) ( $GLOBALS['akibara_byp_filters'] ?? array() ) as $f ) {
			if ( 'woocommerce_valid_order_statuses_for_order_again' === $f[0] && '__return_empty_array' === $f[1] ) {
				$registrado = true;
			}
		}

		self::assertTrue( $registrado, 'La lista de estados válidos para order-again debe quedar VACÍA de raíz.' );
	}

	/**
	 * @testdox El copy del header de Direcciones vive en un solo sitio: la plantilla.
	 *
	 * S-a32bff78 (2026-09-19): `address_description()` ignoraba el argumento
	 * que le pasa `apply_filters()` y devolvía SIEMPRE su propio literal —el
	 * mismo texto que la plantilla `my-address.php` ya trae como valor por
	 * defecto del filtro. Editar cualquiera de las dos copias no cambiaba
	 * nada: la de la plantilla porque el filtro la pisaba, y la del filtro
	 * porque nadie sospechaba que hubiera otra. Al quitar el filtro de
	 * `MyAccountUx`, el copy queda en una sola fuente —la plantilla— y
	 * cualquier otro plugin que use `woocommerce_my_account_my_address_description`
	 * (el hook sigue vivo, sólo Akibara deja de acapararlo) recibe lo que
	 * pidió, no lo que Akibara decida.
	 */
	public function test_address_description_is_not_hardcoded_twice(): void {
		self::assertFalse(
			method_exists( MyAccountUx::class, 'address_description' ),
			'El método que pisaba el copy de Direcciones debe estar borrado, no comentado.'
		);

		// Patrón de la casa (ver test_order_again_is_dead_on_every_surface):
		// stub de add_filter que registra a $GLOBALS para leer qué se enganchó.
		if ( ! function_exists( 'add_filter' ) ) {
			eval(
				'namespace { function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					$GLOBALS["akibara_byp_filters"][] = array( $hook, $callback, $priority, $accepted_args );
					return true;
				} }'
			);
		}
		if ( ! function_exists( 'add_action' ) ) {
			eval(
				'namespace { function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					return true;
				} }'
			);
		}
		$GLOBALS['akibara_byp_filters'] = array();

		( new MyAccountUx() )->register();

		foreach ( (array) ( $GLOBALS['akibara_byp_filters'] ?? array() ) as $f ) {
			self::assertNotSame(
				'woocommerce_my_account_my_address_description',
				$f[0],
				'MyAccountUx no debe volver a acaparar este hook: el copy vive en la plantilla.'
			);
		}

		$template = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/myaccount/my-address.php' );
		self::assertStringContainsString(
			"apply_filters( 'woocommerce_my_account_my_address_description', 'Estas son tus direcciones guardadas. Las usamos al pagar.' )",
			$template,
			'La plantilla sigue siendo la única dueña del copy y del valor por defecto del filtro.'
		);
	}
}
