<?php
/**
 * El cliente que retira tiene que saber CÓMO. Medido el 2026-09-11 con una
 * compra real en el espejo (#30708): el correo decía «Retiro en Metro San
 * Miguel ¡GRATIS!» y nada más.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PickupCoordination;

use Akibara\Business\Modules\PickupCoordination\PickupCoordination;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class RetiroSeExplicaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->alias(
			static fn( $texto ): string => htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $texto ): string => strip_tags( (string) $texto )
		);
		Functions\when( 'wp_kses' )->alias(
			static fn( $texto, $allowed ): string => '' === $allowed || array() === $allowed ? strip_tags( (string) $texto ) : (string) $texto
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'get_option' )->justReturn( false );
		// Sin este doble, el resultado depende de si OTRO test definió `is_email`
		// antes [[function-exists-miente-en-la-suite]].
		Functions\when( 'is_email' )->alias(
			static fn( $correo ) => is_string( $correo ) && false !== filter_var( $correo, FILTER_VALIDATE_EMAIL ) ? $correo : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Pedido con el método de envío indicado.
	 *
	 * @param string $method_id Id del método (local_pickup, flat_rate, …).
	 * @param string $status    Estado del pedido (default: `processing`, uno vigente).
	 */
	private function pedido( string $method_id, string $status = 'processing' ): WC_Order {
		$linea = new class( $method_id ) {
			public function __construct( private string $id ) {}

			public function get_method_id(): string {
				return $this->id;
			}
		};

		$pedido = new class( $linea ) extends WC_Order {
			/** @param object $linea Línea de envío. */
			public function __construct( private $linea ) {}

			/** @return array<int, object> */
			public function get_shipping_methods(): array {
				return '' === (string) $this->linea->get_method_id() ? array() : array( $this->linea );
			}

			public function get_order_number(): string {
				return '30708';
			}
		};
		$pedido->akb_status = $status;

		return $pedido;
	}

	private function correo( string $method_id, bool $a_admin = false, bool $plano = false, string $status = 'processing' ): string {
		ob_start();
		( new PickupCoordination() )->pintar_en_correo( $this->pedido( $method_id, $status ), $a_admin, $plano );

		return (string) ob_get_clean();
	}

	/**
	 * @testdox El correo de un retiro explica que se coordina y ofrece WhatsApp y correo.
	 */
	public function test_el_retiro_se_explica_en_el_correo(): void {
		$html = $this->correo( 'local_pickup' );

		self::assertStringContainsString( 'Metro San Miguel', $html );
		self::assertStringContainsString( 'coordinamos', $html, 'Lo que el dueño pidió decir: que se coordina.' );
		self::assertStringContainsString( 'https://wa.me/56944242844', $html );
		self::assertStringContainsString( 'mailto:contacto@akibara.cl', $html );
		self::assertStringContainsString( '#30708', $html, 'Con el número de pedido, o el cliente escribe «hola» y hay que preguntárselo.' );
	}

	/**
	 * @testdox La versión de texto plano dice lo mismo, con el teléfono legible y sin codificar entidades HTML en apóstrofes o ampersands.
	 */
	public function test_el_texto_plano_tambien_lo_dice(): void {
		$texto = $this->correo( 'local_pickup', false, true );

		self::assertStringContainsString( 'METRO SAN MIGUEL', $texto );
		self::assertStringContainsString( '+56 9 4424 2844', $texto, 'En texto plano no hay enlace: el número tiene que poder leerse.' );
		self::assertStringContainsString( 'contacto@akibara.cl', $texto );
		self::assertStringNotContainsString( '<', $texto, 'Texto plano es texto plano.' );

		Functions\when( 'get_option' )->alias(
			static fn( string $n, $def = false ) => match ( $n ) {
				PickupCoordination::OPTION_TITULO => "Retiro D'Angelo & Manga",
				PickupCoordination::OPTION_CUERPO => "Coordinamos D'Angelo & Co. rápido.",
				default                           => $def,
			}
		);
		$texto_especial = $this->correo( 'local_pickup', false, true );
		self::assertStringContainsString( "D'Angelo & Co.", $texto_especial );
		self::assertStringNotContainsString( '&#039;', $texto_especial );
		self::assertStringNotContainsString( '&amp;', $texto_especial );
	}

	/**
	 * @testdox Un pedido con despacho a domicilio no lo ve. Ni el retiro en punto Blue Express.
	 * @dataProvider envios_que_no_son_retiro_nuestro
	 */
	public function test_lo_que_no_es_retiro_nuestro_no_lo_ve( string $method_id ): void {
		self::assertSame( '', $this->correo( $method_id ) );
		self::assertSame( '', $this->correo( $method_id, false, true ) );
	}

	/** @return array<string, array{0: string}> */
	public static function envios_que_no_son_retiro_nuestro(): array {
		return array(
			'despacho a domicilio'    => array( 'flat_rate' ),
			'Blue Express a domicilio' => array( 'bluex' ),
			'punto Blue Express'      => array( 'bluex-pudo' ),
			'envío gratis'            => array( 'free_shipping' ),
			'sin envío'               => array( '' ),
		);
	}

	/**
	 * @testdox El correo al administrador no lo lleva: él ya sabe cómo entrega.
	 */
	public function test_el_admin_no_lo_ve(): void {
		self::assertSame( '', $this->correo( 'local_pickup', true ) );
	}

	/**
	 * @testdox En pantalla (gracias y Mi cuenta) se pinta igual.
	 */
	public function test_en_pantalla_tambien(): void {
		ob_start();
		( new PickupCoordination() )->pintar_en_pantalla( $this->pedido( 'local_pickup' ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Metro San Miguel', $html );

		ob_start();
		( new PickupCoordination() )->pintar_en_pantalla( $this->pedido( 'flat_rate' ) );
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox El teléfono sale del plugin de WhatsApp cuando está configurado.
	 */
	public function test_el_telefono_sale_de_la_configuracion(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $n ) => 'joinchat' === $n ? array( 'telephone' => '+56912345678' ) : false
		);

		$html = $this->correo( 'local_pickup' );

		self::assertStringContainsString( 'https://wa.me/56912345678', $html );
		self::assertStringNotContainsString( '944242844', $html, 'El respaldo sólo vale si no hay número configurado.' );
	}

	/**
	 * @testdox El punto de entrega y la explicación salen de Akibara → Textos.
	 */
	public function test_el_texto_del_retiro_lo_escribe_el_dueno(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $n, $def = false ) => match ( $n ) {
				PickupCoordination::OPTION_TITULO => 'Cómo es el retiro en Plaza Ñuñoa',
				PickupCoordination::OPTION_CUERPO => 'Te avisamos y quedamos en la plaza.',
				'akibara_forms_notify_email'      => 'hola@akibara.cl',
				default                           => $def,
			}
		);

		$html = $this->correo( 'local_pickup' );
		// REAPERTURA S-6a9abee3: `rawurlencode()` esconde «Metro San Miguel»
		// como «Metro%20San%20Miguel» dentro del mailto, y el assert de abajo
		// sobre el HTML crudo pasaba igual con el texto viejo adentro. Se
		// decodifica ANTES de comprobar que no quedó nada del punto viejo.
		$visible = urldecode( $html );

		self::assertStringContainsString( 'Plaza Ñuñoa', $html );
		self::assertStringContainsString( 'quedamos en la plaza', $html );
		self::assertStringContainsString( 'mailto:hola@akibara.cl', $html );
		self::assertStringNotContainsString(
			'Metro San Miguel',
			$visible,
			'Ni siquiera porcentificado dentro del mailto: cambiar el punto de entrega no puede exigir un despliegue.'
		);
		self::assertStringNotContainsString( 'contacto@akibara.cl', $visible );
	}

	/**
	 * Hallazgo S-6a9abee3 (reapertura 2026-09-19): título y cuerpo ya se editan
	 * desde Akibara → Textos, pero el asunto prellenado del enlace de correo
	 * seguía escrito a fuego con «Metro San Miguel». Tiene que ser neutro —no
	 * nombrar ningún punto de entrega— para no desincronizarse del recuadro de
	 * arriba cuando el dueño cambia el punto.
	 *
	 * @testdox El asunto del enlace de correo no nombra el punto de entrega.
	 */
	public function test_el_asunto_del_mailto_es_neutro(): void {
		$visible = urldecode( $this->correo( 'local_pickup' ) );

		self::assertStringContainsString( 'subject=Retiro — pedido #30708', $visible );
	}

	/**
	 * @testdox En texto plano manda lo mismo, con el título en mayúsculas.
	 */
	public function test_el_texto_plano_tambien_obedece(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $n, $def = false ) => PickupCoordination::OPTION_TITULO === $n
				? 'Cómo es el retiro en Plaza Ñuñoa'
				: $def
		);

		$texto = $this->correo( 'local_pickup', false, true );

		self::assertStringContainsString( 'CÓMO ES EL RETIRO EN PLAZA ÑUÑOA', $texto );
		self::assertStringNotContainsString( 'METRO SAN MIGUEL', $texto );
	}

	/**
	 * @testdox Un texto borrado no deja el recuadro vacío: vuelve el de fábrica.
	 */
	public function test_el_vacio_vuelve_a_fabrica(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $n, $def = false ) => in_array( $n, array( PickupCoordination::OPTION_TITULO, PickupCoordination::OPTION_CUERPO ), true ) ? '   ' : $def
		);

		$html = $this->correo( 'local_pickup' );

		self::assertStringContainsString( PickupCoordination::DEFAULT_TITULO, $html );
		self::assertStringContainsString( 'coordinamos', $html );
	}

	/**
	 * @testdox El correo de contacto no está escrito en el código del módulo.
	 */
	public function test_el_correo_no_esta_en_el_codigo(): void {
		self::assertStringNotContainsString(
			"'contacto@akibara.cl'",
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/PickupCoordination/PickupCoordination.php' ),
			'El buzón sale de la configuración de Contacto, no de una constante.'
		);
	}

	/**
	 * @testdox El método se decide por el ID real, no por el título que edita el dueño.
	 */
	public function test_se_mira_el_id_no_el_titulo(): void {
		// Un `flat_rate` al que alguien le puso de título «Retiro en Metro San
		// Miguel» NO es un retiro: el título se edita desde WooCommerce.
		self::assertSame( '', $this->correo( 'flat_rate' ) );
	}

	/**
	 * EL DEFECTO QUE CIERRA (S-599c6864, 2026-09-19): `woocommerce_email_after_order_table`
	 * dispara en TODOS los correos al cliente, incluidos «Pedido cancelado» y
	 * «Pedido reembolsado». El único filtro que había era «no es al admin» y
	 * «la línea de envío es retiro» — ninguno mira el ESTADO, así que esos dos
	 * correos también invitaban a coordinar un retiro que no va a pasar.
	 *
	 * @testdox [NEG] Un pedido cancelado o reembolsado no invita a coordinar el retiro, ni en correo ni en pantalla.
	 * @dataProvider estadosSinRetiro
	 */
	public function test_pedido_sin_retiro_no_invita_a_coordinar( string $estado ): void {
		self::assertSame( '', $this->correo( 'local_pickup', false, false, $estado ) );
		self::assertSame( '', $this->correo( 'local_pickup', false, true, $estado ), 'Tampoco en la versión de texto plano.' );

		ob_start();
		( new PickupCoordination() )->pintar_en_pantalla( $this->pedido( 'local_pickup', $estado ) );
		self::assertSame( '', (string) ob_get_clean(), 'Tampoco en la página de gracias ni en Mi cuenta.' );
	}

	/** @return array<string, array{0: string}> */
	public static function estadosSinRetiro(): array {
		return array(
			'cancelado'        => array( 'cancelled' ),
			'reembolsado'      => array( 'refunded' ),
			'pago fallido'     => array( 'failed' ),
		);
	}

	/**
	 * @testdox Un pedido vigente (procesando, en espera o completado) sí lo ve.
	 * @dataProvider estadosConRetiroVigente
	 */
	public function test_pedido_vigente_si_invita_a_coordinar( string $estado ): void {
		self::assertStringContainsString( 'coordinamos', $this->correo( 'local_pickup', false, false, $estado ) );
	}

	/** @return array<string, array{0: string}> */
	public static function estadosConRetiroVigente(): array {
		return array(
			'procesando'  => array( 'processing' ),
			'en espera'   => array( 'on-hold' ),
			'completado'  => array( 'completed' ),
		);
	}

	/**
	 * @testdox En pantalla usa clases limpias del tema oscuro sin estilos inline, y en correo conserva estilos inline con tinta oscura explícita.
	 */
	public function test_pantalla_usa_clases_limpias_y_correo_conserva_contraste_inline(): void {
		$correo_html = $this->correo( 'local_pickup', false, false, 'processing' );
		self::assertStringContainsString( 'class="akb-email-callout akb-pickup-coord"', $correo_html );
		self::assertStringContainsString( 'background: #f7f7f7', $correo_html );
		self::assertStringContainsString( 'color: #0d0d0d;', $correo_html );

		ob_start();
		( new PickupCoordination() )->pintar_en_pantalla( $this->pedido( 'local_pickup', 'processing' ) );
		$pantalla_html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="akb-pickup-coord"', $pantalla_html );
		self::assertStringContainsString( 'class="akb-pickup-coord__title"', $pantalla_html );
		self::assertStringContainsString( 'class="akb-pickup-coord__body"', $pantalla_html );
		self::assertStringContainsString( 'class="akb-pickup-coord__contact"', $pantalla_html );
		self::assertStringNotContainsString( 'style=', $pantalla_html );
	}

	/**
	 * @testdox [P0-3] La etiqueta del método en checkout añade la promesa de coordinación con clase CSS en local_pickup y pickup_location.
	 */
	public function test_agregar_nota_en_tabla_envio_anade_promesa_sin_estilos_inline(): void {
		$pickup = new class() {
			public function get_method_id(): string {
				return 'local_pickup';
			}
		};
		$pickup_location = new class() {
			public function get_method_id(): string {
				return 'pickup_location';
			}
		};

		$coord = new PickupCoordination();

		$out_local = $coord->agregar_nota_en_tabla_envio( 'Retiro Metro San Miguel: Gratis', $pickup );
		self::assertStringContainsString( 'class="akb-pickup-promise-label"', $out_local );
		self::assertStringContainsString( 'te contactaremos en menos de 24 horas', $out_local );
		self::assertStringNotContainsString( 'style=', $out_local );

		$out_loc = $coord->agregar_nota_en_tabla_envio( 'Retiro en tienda', $pickup_location );
		self::assertStringContainsString( 'class="akb-pickup-promise-label"', $out_loc );
	}

	/**
	 * @testdox [NEG] Métodos distintos a retiro nuestro o entradas no válidas dejan intacta la etiqueta de envío.
	 * @dataProvider envios_que_no_son_retiro_nuestro
	 */
	public function test_agregar_nota_en_tabla_envio_omite_otros_metodos_y_entradas_invalidas( string $method_id ): void {
		$rate = new class( $method_id ) {
			public function __construct( private string $id ) {}

			public function get_method_id(): string {
				return $this->id;
			}
		};

		$coord    = new PickupCoordination();
		$original = 'Despacho Blue Express: $3.990';

		self::assertSame( $original, $coord->agregar_nota_en_tabla_envio( $original, $rate ) );
		self::assertSame( $original, $coord->agregar_nota_en_tabla_envio( $original, null ) );
		self::assertSame( $original, $coord->agregar_nota_en_tabla_envio( $original, 'local_pickup' ) );
		self::assertSame( $original, $coord->agregar_nota_en_tabla_envio( $original, new \stdClass() ) );
	}

	/**
	 * @testdox [P0-3] register() enlaza tanto el filtro de Store API / Bloques (woocommerce_package_rates) como el de plantilla clásica.
	 */
	public function test_register_hooks_incluye_store_api_y_plantilla_clasica(): void {
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_email_after_order_table' )->once();
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_order_details_after_order_table' )->once();
		\Brain\Monkey\Filters\expectAdded( 'woocommerce_package_rates' )->once();
		\Brain\Monkey\Filters\expectAdded( 'woocommerce_order_item_get_formatted_meta_data' )->once();
		\Brain\Monkey\Filters\expectAdded( 'woocommerce_cart_shipping_method_full_label' )->once();

		( new PickupCoordination() )->register();

		self::assertTrue( true );
	}

	/**
	 * @testdox [P0-3] asignar_tiempo_entrega_retiro() rellena delivery_time y pickup_details para local_pickup y pickup_location en Store API sin pisar valores existentes ni tarifas externas.
	 */
	public function test_asignar_tiempo_entrega_retiro_solo_aplica_a_retiro_propio_sin_delivery_time_previo(): void {
		$mk_rate = static fn( string $method_id, string $delivery_time = '', array $meta = array() ): object => new class( $method_id, $delivery_time, $meta ) {
			/** @param array<string, mixed> $meta */
			public function __construct( private string $id, private string $delivery, private array $meta ) {}

			public function get_method_id(): string {
				return $this->id;
			}

			public function get_delivery_time(): string {
				return $this->delivery;
			}

			public function set_delivery_time( string $delivery ): void {
				$this->delivery = $delivery;
			}

			/** @return array<string, mixed> */
			public function get_meta_data(): array {
				return $this->meta;
			}

			public function add_meta_data( string $key, string $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		$pickup       = $mk_rate( 'local_pickup' );
		$pickup_loc   = $mk_rate( 'pickup_location', '   ' );
		$pickup_keep  = $mk_rate( 'local_pickup', 'Hoy mismo antes de las 18:00', array( 'pickup_details' => 'Horario propio' ) );
		$bluex        = $mk_rate( 'bluex' );
		$bluex_pudo   = $mk_rate( 'bluex-pudo', '2 a 3 días hábiles' );
		$invalid_item = 'not-a-rate';

		$rates = array(
			'local_pickup:70'     => $pickup,
			'pickup_location:1'   => $pickup_loc,
			'local_pickup:custom' => $pickup_keep,
			'bluex:1'             => $bluex,
			'bluex-pudo:2'        => $bluex_pudo,
			'corrupt'             => $invalid_item,
		);

		$out = ( new PickupCoordination() )->asignar_tiempo_entrega_retiro( $rates );

		self::assertSame( PickupCoordination::PROMESA_TABLA_ENVIO, $out['local_pickup:70']->get_delivery_time() );
		self::assertSame( PickupCoordination::PROMESA_TABLA_ENVIO, $out['local_pickup:70']->get_meta_data()['pickup_details'] ?? '' );
		self::assertSame( PickupCoordination::PROMESA_TABLA_ENVIO, $out['pickup_location:1']->get_delivery_time() );
		self::assertSame( PickupCoordination::PROMESA_TABLA_ENVIO, $out['pickup_location:1']->get_meta_data()['pickup_details'] ?? '' );
		self::assertSame( 'Hoy mismo antes de las 18:00', $out['local_pickup:custom']->get_delivery_time() );
		self::assertSame( 'Horario propio', $out['local_pickup:custom']->get_meta_data()['pickup_details'] ?? '' );
		self::assertSame( '', $out['bluex:1']->get_delivery_time() );
		self::assertArrayNotHasKey( 'pickup_details', $out['bluex:1']->get_meta_data() );
		self::assertSame( '2 a 3 días hábiles', $out['bluex-pudo:2']->get_delivery_time() );
		self::assertSame( 'not-a-rate', $out['corrupt'] );
	}

	/**
	 * @testdox [P0-3] ocultar_meta_promesa_en_orden() limpia solo la clave pickup_details sintética de retiro propio.
	 */
	public function test_ocultar_meta_promesa_en_orden_filtra_solo_promesa_sintetica_en_retiro(): void {
		$coord = new PickupCoordination();
		$m1    = (object) array( 'key' => 'pickup_details', 'value' => PickupCoordination::PROMESA_TABLA_ENVIO );
		$m2    = (object) array( 'key' => 'pickup_location', 'value' => 'Metro San Miguel' );
		$m3    = (object) array( 'key' => 'pickup_details', 'value' => 'Otra nota distinta' );

		$pickup_item = new class() {
			public function get_method_id(): string {
				return 'local_pickup';
			}
		};
		$bluex_item = new class() {
			public function get_method_id(): string {
				return 'bluex';
			}
		};

		$filtered_pickup = array_values( $coord->ocultar_meta_promesa_en_orden( array( $m1, $m2, $m3 ), $pickup_item ) );
		self::assertSame( array( $m2, $m3 ), $filtered_pickup );

		$filtered_bluex = array_values( $coord->ocultar_meta_promesa_en_orden( array( $m1, $m2 ), $bluex_item ) );
		self::assertSame( array( $m1, $m2 ), $filtered_bluex );
	}
}
