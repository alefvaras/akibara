<?php
/**
 * Candado del plazo de Blue Express en el checkout de bloques; no carga
 * WordPress ni abre red.
 *
 * INVARIANTES QUE FIJA:
 * 1. Sólo las tarifas `bluex-*` publican plazo, con el texto de Blue tal cual.
 * 2. `PrazoEntrega = '0'` (lo que Blue deja cuando su cotizador falla) NO se
 *    publica: no es «llega hoy», es «no sé».
 * 3. Un carrito con algo `onbackorder` (a pedido / preventa) marca
 *    `pending_stock`, para que el cliente no lea el plazo de Blue como si el
 *    paquete saliera hoy.
 * 4. Si no hay paquetes calculados, el mapa va vacío (y el bundle no pinta).
 * 5. (2.2.28) `typical` sólo lleva días habituales MENORES que el máximo del
 *    texto de Blue, y sólo en comunas de la tabla; cada clave de la tabla es una
 *    comuna real del catálogo de Blue (si el plugin de Blue está al lado).
 *
 * Uso: php tools/test-shipping-promise.php <ruta a akibara-cart-checkout-blocks>
 */

declare( strict_types = 1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function wp_strip_all_tags( string $s ): string {
		return trim( strip_tags( $s ) );
	}

	class WC_Shipping_Rate {
		/** @param array<string,mixed> $meta */
		public function __construct( private string $method_id, private array $meta ) {}
		public function get_method_id(): string {
			return $this->method_id;
		}
		/** @return array<string,mixed> */
		public function get_meta_data(): array {
			return $this->meta;
		}
	}

	class WC_Product {
		public function __construct( private string $status ) {}
		public function get_stock_status(): string {
			return $this->status;
		}
	}

	final class Akb_Test_Cart {
		/** @param array<int,array<string,mixed>> $items */
		public function __construct( public array $items ) {}
		/** @return array<int,array<string,mixed>> */
		public function get_cart(): array {
			return $this->items;
		}
	}

	final class Akb_Test_Shipping {
		/** @param array<int,array<string,mixed>> $packages */
		public function __construct( public array $packages ) {}
		/** @return array<int,array<string,mixed>> */
		public function get_packages(): array {
			return $this->packages;
		}
	}

	final class Akb_Test_WC {
		public ?Akb_Test_Cart $cart = null;
		public ?Akb_Test_Shipping $ship = null;
		public function shipping(): ?Akb_Test_Shipping {
			return $this->ship;
		}
	}

	$GLOBALS['akb_wc'] = new Akb_Test_WC();
	function WC(): Akb_Test_WC { // phpcs:ignore
		return $GLOBALS['akb_wc'];
	}
}

namespace Akibara\CartCheckoutBlocks {
	final class BlocksPage {}
}

namespace {
	$plugin = isset( $argv[1] ) && '--con-directorio' !== $argv[1] ? $argv[1] : dirname( __DIR__ ) . '/public_html/wp-content/plugins/akibara-cart-checkout-blocks';
	require $plugin . '/src/ShippingPromise.php';
	$with_directory = in_array( '--con-directorio', $argv, true );
	if ( $with_directory ) {
		require $plugin . '/src/ComunaDirectory.php';
	}

	use Akibara\CartCheckoutBlocks\ShippingPromise;

	$fails = 0;
	$check = static function ( bool $ok, string $msg ) use ( &$fails ): void {
		echo ( $ok ? 'ok   ' : 'FAIL ' ) . $msg . PHP_EOL;
		if ( ! $ok ) {
			++$fails;
		}
	};

	// 1 y 2 — mapa por rate_id.
	$packages = array(
		0 => array(
			'rates' => array(
				'bluex-ex21'     => new WC_Shipping_Rate( 'bluex-ex', array( '_delivery_forecast' => 'Hasta 2 días hábiles' ) ),
				'bluex-pudo112'  => new WC_Shipping_Rate( 'bluex-pudo', array( '_delivery_forecast' => 'Hasta 2 días hábiles' ) ),
				'local_pickup:70'=> new WC_Shipping_Rate( 'local_pickup', array( '_delivery_forecast' => 'Hasta 2 días hábiles' ) ),
				'bluex-ex99'     => new WC_Shipping_Rate( 'bluex-ex', array( '_delivery_forecast' => '0' ) ),
				'bluex-ex98'     => new WC_Shipping_Rate( 'bluex-ex', array() ),
			),
		),
	);
	$map = ShippingPromise::rates_from_packages( $packages );
	$check( ( $map['bluex-ex21'] ?? null ) === 'Hasta 2 días hábiles', 'Express publica el texto de Blue tal cual' );
	$check( isset( $map['bluex-pudo112'] ), 'el retiro en punto también publica su plazo' );
	$check( ! isset( $map['local_pickup:70'] ), 'una tarifa que no es de Blue no publica plazo' );
	$check( ! isset( $map['bluex-ex99'] ), "PrazoEntrega '0' (cotizador caído) no se publica" );
	$check( ! isset( $map['bluex-ex98'] ), 'sin meta no hay plazo' );

	// Limpieza.
	$check( ShippingPromise::clean( '3' ) === 'Hasta 3 días hábiles', 'un número a secas se redacta como Blue' );
	$check( ShippingPromise::clean( '1' ) === 'Hasta 1 día hábil', 'singular' );
	$check( ShippingPromise::clean( '<b>Hasta 15 días hábiles</b>' ) === 'Hasta 15 días hábiles', 'sin marcado' );
	$check( ShippingPromise::clean( 'Consultar' ) === '', 'texto sin plazo se descarta' );
	$check( ShippingPromise::clean( str_repeat( 'x1', 60 ) ) === '', 'texto desbocado se descarta' );
	$check( ShippingPromise::clean( array( 'x' ) ) === '', 'no escalar se descarta' );

	// 3 — pending_stock.
	WC()->cart = new Akb_Test_Cart( array( array( 'data' => new WC_Product( 'instock' ) ) ) );
	$check( false === ShippingPromise::cart_has_pending_stock(), 'carrito en bodega: sin aviso' );
	WC()->cart = new Akb_Test_Cart(
		array(
			array( 'data' => new WC_Product( 'instock' ) ),
			array( 'data' => new WC_Product( 'onbackorder' ) ),
		)
	);
	$check( true === ShippingPromise::cart_has_pending_stock(), 'carrito mixto con preventa: pending_stock' );

	// 4 — cart_data completo.
	WC()->ship = null;
	$empty = ( new ShippingPromise( new Akibara\CartCheckoutBlocks\BlocksPage() ) )->cart_data();
	$check( array() === (array) $empty['rates'] && 1 === $empty['version'], 'sin paquetes el mapa va vacío' );
	WC()->ship = new Akb_Test_Shipping( $packages );
	$full = ( new ShippingPromise( new Akibara\CartCheckoutBlocks\BlocksPage() ) )->cart_data();
	$check( is_object( $full['rates'] ) && 2 === count( (array) $full['rates'] ), 'rates viaja como objeto JSON con las dos tarifas Blue' );
	$check( true === $full['pending_stock'], 'pending_stock viaja en la respuesta' );
	$check( '{"bluex-ex21":"Hasta 2 días hábiles","bluex-pudo112":"Hasta 2 días hábiles"}' === json_encode( $full['rates'], JSON_UNESCAPED_UNICODE ), 'forma JSON estable' );

	// 5 — días habituales.
	echo PHP_EOL . '# typical (normalizador: ' . ( $with_directory ? 'ComunaDirectory' : 'respaldo interno' ) . ')' . PHP_EOL;
	$check( 1 === ShippingPromise::typical_days_for( 'Providencia' ), 'Providencia: 1 día habitual' );
	$check( 1 === ShippingPromise::typical_days_for( '  ÑUÑOA ' ), 'Ñuñoa con mayúsculas, eñes y espacios' );
	$check( 11 === ShippingPromise::typical_days_for( 'Aysén' ), 'Aysén: 11' );
	$check( null === ShippingPromise::typical_days_for( 'Valparaíso' ), 'Valparaíso no está en la tabla' );
	$check( null === ShippingPromise::typical_days_for( '' ), 'comuna vacía: sin dato' );
	$check( 3 === ShippingPromise::max_days( 'Hasta 3 días hábiles' ) && null === ShippingPromise::max_days( 'Pronto' ), 'max_days lee el número de Blue' );
	$check( 7 === ShippingPromise::max_days( 'Desde 5 Hasta 7 días hábiles' ), 'con rango, el máximo es el de «hasta»' );
	$check( 4 === ShippingPromise::max_days( 'Entre 2 y 4 días' ), 'sin «hasta», el último número' );

	$rm = array(
		0 => array(
			'destination' => array( 'city' => 'Providencia', 'state' => 'CL-RM', 'country' => 'CL' ),
			'rates'       => array(
				'bluex-ex21'      => new WC_Shipping_Rate( 'bluex-ex', array( '_delivery_forecast' => 'Hasta 2 días hábiles' ) ),
				'bluex-pudo:112'  => new WC_Shipping_Rate( 'bluex-pudo', array( '_delivery_forecast' => 'Hasta 2 días hábiles' ) ),
				'local_pickup:70' => new WC_Shipping_Rate( 'local_pickup', array() ),
			),
		),
	);
	$typ = ShippingPromise::typical_from_packages( $rm );
	$check( array( 'bluex-ex21' => 1, 'bluex-pudo:112' => 1 ) === $typ, 'RM: 1 día habitual en domicilio y punto, nada en retiro en tienda' );

	$rm[0]['rates']['bluex-ex21'] = new WC_Shipping_Rate( 'bluex-ex', array( '_delivery_forecast' => 'Hasta 1 día hábil' ) );
	$typ = ShippingPromise::typical_from_packages( $rm );
	$check( ! isset( $typ['bluex-ex21'] ) && isset( $typ['bluex-pudo:112'] ), 'si Blue ya promete 1 día, no se añade «normalmente»' );

	$range = $rm;
	$range[0]['rates']['bluex-ex21'] = new WC_Shipping_Rate( 'bluex-ex', array( '_delivery_forecast' => 'Desde 2 Hasta 3 días hábiles' ) );
	$check( ! isset( ShippingPromise::typical_from_packages( $range )['bluex-ex21'] ), 'si Blue ya manda un rango, no se añade «normalmente»' );

	$vp = array( 0 => array( 'destination' => array( 'city' => 'Valparaíso' ), 'rates' => $rm[0]['rates'] ) );
	$check( array() === ShippingPromise::typical_from_packages( $vp ), 'comuna fuera de la tabla: sin typical' );
	$check( array() === ShippingPromise::typical_from_packages( array( 0 => 'basura', 1 => array( 'rates' => $rm[0]['rates'] ) ) ), 'paquete sin destino: sin typical' );

	WC()->ship = new Akb_Test_Shipping( $rm );
	$full = ( new ShippingPromise( new Akibara\CartCheckoutBlocks\BlocksPage() ) )->cart_data();
	$check( is_object( $full['typical'] ) && '{"bluex-pudo:112":1}' === json_encode( $full['typical'] ), 'typical viaja como objeto JSON' );
	WC()->ship = null;
	$empty = ( new ShippingPromise( new Akibara\CartCheckoutBlocks\BlocksPage() ) )->cart_data();
	$check( '{}' === json_encode( $empty['typical'] ), 'sin paquetes typical es {} (no [])' );
	$schema = ( new ShippingPromise( new Akibara\CartCheckoutBlocks\BlocksPage() ) )->cart_schema();
	$check( 'integer' === ( $schema['typical']['additionalProperties']['type'] ?? '' ), 'esquema declara typical' );

	// Cada clave de la tabla es una comuna real del catálogo de Blue.
	$table  = ( new ReflectionClassConstant( ShippingPromise::class, 'TYPICAL_DAYS' ) )->getValue();
	$vendor = dirname( $plugin ) . '/bluex-for-woocommerce/includes/data/chile-communes.php';
	if ( is_readable( $vendor ) ) {
		require_once $vendor;
		$known = array();
		foreach ( bluex_get_communes_by_region() as $communes ) {
			foreach ( $communes as $c ) {
				$known[ ShippingPromise::typical_days_for( $c['name'] ) === null ? '' : $c['name'] ] = true;
			}
		}
		unset( $known[''] );
		$check( count( $table ) === count( $known ), 'las ' . count( $table ) . ' claves de la tabla calzan con comunas del catálogo de Blue (' . count( $known ) . ')' );
	} else {
		echo 'skip catálogo de Blue no disponible en ' . $vendor . PHP_EOL;
	}
	$check( 71 === count( $table ) && min( $table ) >= 1, 'tabla: 71 comunas, días ≥ 1' );

	echo PHP_EOL . ( $fails ? "{$fails} FALLO(S)" : 'TODO VERDE' ) . PHP_EOL;
	exit( $fails ? 1 : 0 );
}
