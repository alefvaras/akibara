<?php
/**
 * La foto del consentimiento de ofertas y novedades en el pedido.
 *
 * Lo que prueba: que con la clave del campo y `true` se guarda la prueba
 * completa (valor, etiqueta leída del registro de WooCommerce —la que el
 * checkout PINTÓ, es decir `optionalLabel` para un campo opcional—, fecha ISO,
 * origen, política) y una nota privada; que `false` deja «NO»; que otra clave
 * o un `WC_Customer` no escriben; que una edición desde el admin no toca la
 * prueba pero deja nota si cambia el valor; que repetir la misma decisión
 * (PUT + POST del checkout) no reescribe ni vuelve a anotar; que la casilla
 * que el bloque NO envía (sin tocar) queda como «NO» al cerrar el pedido; y
 * que un pedido procesado sin la prueba deja un warning por pedido.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Blocks {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) ) {
		/** Doble del contenedor de Blocks: sólo `container()->get()`. */
		class Package {
			/** @return object */
			public static function container() {
				return new class() {
					/**
					 * @param string $id Clase pedida.
					 * @return object
					 */
					public function get( string $id ) {
						unset( $id );
						return new \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields();
					}
				};
			}
		}
	}
}

namespace Automattic\WooCommerce\Blocks\Domain\Services {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
		/**
		 * Doble del registro de campos adicionales del checkout. Modela el
		 * registro REAL: cada campo trae `required` y `optionalLabel` (WooCommerce
		 * los rellena siempre, CheckoutFields.php:209), y el valor guardado de un
		 * checkbox se lee de la meta `_wc_{grupo}/{clave}` como bool.
		 */
		class CheckoutFields {
			/** @return array<string, array<string, mixed>> */
			public function get_additional_fields(): array {
				return $GLOBALS['akibara_test_checkout_fields'] ?? array();
			}

			/**
			 * @param string $key       Clave del campo.
			 * @param object $wc_object Pedido o cliente.
			 * @param string $group     Grupo.
			 * @return mixed
			 */
			public function get_field_from_object( string $key, $wc_object, string $group = 'other' ) {
				$value = $wc_object->get_meta( '_wc_' . $group . '/' . $key, true );

				return '1' === (string) $value;
			}
		}
	}
}

namespace {
	if ( ! class_exists( 'WC_Customer' ) ) {
		/**
		 * Doble mínimo: el módulo sólo pregunta `instanceof`. Lleva `get_meta()`
		 * porque la suite de akibara-cart-checkout-blocks corre en el mismo
		 * proceso y su `CheckoutFieldsTest` lo necesita: sin él, el que cargue
		 * primero rompe al otro (medido por QA el 2026-09-11).
		 */
		class WC_Customer {
			/**
			 * @param string $key   Meta.
			 * @param mixed  $value Valor.
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->akb_meta[ $key ] = $value;
			}

			/**
			 * @param string $key    Meta.
			 * @param bool   $single Una sola.
			 * @return mixed
			 */
			public function get_meta( string $key, bool $single = true ) {
				unset( $single );
				return $this->akb_meta[ $key ] ?? '';
			}

			/** @var array<string, mixed> */
			public array $akb_meta = array();
		}
	}
}

namespace Akibara\Business\Tests\Modules\MarketingConsent {

	use Akibara\Business\Modules\MarketingConsent\MarketingConsent;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class MarketingConsentTest extends TestCase {

		private const CLAVE = 'SendinblueWoocommerce/newsletter_opt_in';

		/** `label` del registro: lo que el proveedor declaró. */
		private const ETIQUETA = 'Quiero recibir por correo ofertas y novedades de Akibara. Es opcional y puedo darme de baja cuando quiera con el enlace que va en cada correo.';

		/** `optionalLabel`: lo que el checkout PINTA para un campo opcional. */
		private const ETIQUETA_VISTA = self::ETIQUETA . ' (opcional)';

		private bool $admin = false;

		/** @var array<int, string> Warnings escritos en el log. */
		private array $warnings = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->admin    = false;
			$this->warnings = array();

			$GLOBALS['akibara_test_checkout_fields'] = array(
				self::CLAVE   => array(
					'label'         => self::ETIQUETA,
					'optionalLabel' => self::ETIQUETA_VISTA,
					'required'      => false,
					'location'      => 'order',
					'type'          => 'checkbox',
				),
				'akibara/rut' => array(
					'label'         => 'RUT',
					'optionalLabel' => 'RUT (opcional)',
					'required'      => true,
					'location'      => 'address',
					'type'          => 'text',
				),
			);

			Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
			Functions\when( 'is_admin' )->alias( fn (): bool => $this->admin );
			Functions\when( 'get_option' )->alias(
				static fn ( string $option, $default_value = false ) => 'wp_page_for_privacy_policy' === $option ? 23640 : $default_value
			);
			Functions\when( 'get_post_field' )->alias(
				static fn ( string $field, int $post_id ) => 'post_modified_gmt' === $field && 23640 === $post_id ? '2026-08-06 14:30:07' : ''
			);
			// Doble fiel de `wp_date()`: Chile en horario de invierno es UTC-4
			// (la misma cifra que cita el hallazgo S-69a9a4ee). Sin este doble,
			// un `gmdate()` de mentira haría que el candado de abajo no probara
			// nada: pasaría igual con el bug y con el arreglo.
			Functions\when( 'wp_date' )->alias(
				static fn ( string $format, int $timestamp ) => gmdate( $format, $timestamp - ( 4 * 3600 ) )
			);
			Functions\when( 'wc_get_logger' )->alias(
				fn () => new class( $this->warnings ) {
					/** @var array<int, string> */
					private array $sink;

					/** @param array<int, string> $sink Acumulador compartido. */
					public function __construct( array &$sink ) {
						$this->sink = &$sink;
					}

					/** @param array<string, mixed> $context Contexto. */
					public function warning( string $message, array $context = array() ): void {
						unset( $context );
						$this->sink[] = $message;
					}
				}
			);
		}

		protected function tearDown(): void {
			unset( $GLOBALS['akibara_test_checkout_fields'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @param \WC_Order $pedido Pedido.
		 * @return array<string, mixed>
		 */
		private function prueba( \WC_Order $pedido ): array {
			$raw = $pedido->get_meta( MarketingConsent::META_KEY, true );
			self::assertIsString( $raw );
			$decoded = json_decode( $raw, true );
			self::assertIsArray( $decoded, 'La prueba se guarda como JSON.' );

			return $decoded;
		}

		/** @testdox Casilla marcada: guarda la prueba completa y una nota «SÍ» con el texto visto. */
		public function test_casilla_marcada_guarda_la_prueba_y_anota_si(): void {
			$pedido = new \WC_Order();

			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );

			$prueba = $this->prueba( $pedido );
			self::assertTrue( $prueba['value'] );
			self::assertSame( self::ETIQUETA_VISTA, $prueba['label'], 'La etiqueta es la que el checkout pintó: optionalLabel para un campo opcional.' );
			self::assertSame( 'checkout-blocks', $prueba['source'] );
			self::assertSame( '2026-08-06 14:30:07', $prueba['policy_modified_gmt'] );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $prueba['at'], 'Fecha ISO 8601 en UTC.' );

			self::assertCount( 1, $pedido->akb_notes );
			self::assertSame(
				'Consentimiento de ofertas y novedades: SÍ — casilla del checkout marcada. Texto que vio el cliente: “' . self::ETIQUETA_VISTA . '”. Política vigente del 06/08/2026.',
				$pedido->akb_notes[0]
			);
			self::assertSame( 0, $pedido->akb_saves, 'No llama a save(): el Store API guarda después.' );
		}

		/**
		 * El checkout de bloques pinta `required ? label : optionalLabel`
		 * (checkout.js). La casilla de ofertas es opcional por definición, y
		 * WooCommerce siempre rellena `optionalLabel` («{label} (opcional)» si
		 * el proveedor no declara otro). Guardar `label` era guardar un texto
		 * que el cliente nunca leyó.
		 *
		 * @testdox La etiqueta guardada es la que el cliente vio: optionalLabel si el campo es opcional, label si es obligatorio.
		 */
		public function test_la_etiqueta_guardada_es_la_que_pinta_el_checkout(): void {
			// Opcional con optionalLabel propio del proveedor: se guarda ése.
			$GLOBALS['akibara_test_checkout_fields'][ self::CLAVE ]['optionalLabel'] = 'Sí, quiero ofertas (puedes cambiarlo después)';
			$pedido = new \WC_Order();
			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertSame( 'Sí, quiero ofertas (puedes cambiarlo después)', $this->prueba( $pedido )['label'] );

			// Obligatorio: el checkout pinta `label`, así que se guarda `label`.
			$GLOBALS['akibara_test_checkout_fields'][ self::CLAVE ]['required'] = true;
			$pedido = new \WC_Order();
			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertSame( self::ETIQUETA, $this->prueba( $pedido )['label'] );

			// Opcional sin optionalLabel (registro incompleto): respaldo en `label`.
			$GLOBALS['akibara_test_checkout_fields'][ self::CLAVE ]['required'] = false;
			unset( $GLOBALS['akibara_test_checkout_fields'][ self::CLAVE ]['optionalLabel'] );
			$pedido = new \WC_Order();
			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertSame( self::ETIQUETA, $this->prueba( $pedido )['label'] );
		}

		/** @testdox Casilla sin marcar: value=false y nota «NO». */
		public function test_casilla_sin_marcar_guarda_no(): void {
			$pedido = new \WC_Order();

			( new MarketingConsent() )->on_field_value( self::CLAVE, false, 'other', $pedido );

			self::assertFalse( $this->prueba( $pedido )['value'] );
			self::assertSame(
				array( 'Consentimiento de ofertas y novedades: NO — casilla del checkout sin marcar. Texto: “' . self::ETIQUETA_VISTA . '”.' ),
				$pedido->akb_notes
			);
		}

		/**
		 * WooCommerce entrega el valor como bool (JSON) o como '1'/'0'
		 * (form-data). Las dos formas afirmativas se entienden igual.
		 *
		 * @testdox Las formas afirmativas se entienden igual.
		 * @dataProvider valoresAfirmativos
		 * @param mixed $valor Valor del campo.
		 */
		public function test_las_formas_afirmativas_se_entienden_igual( $valor ): void {
			$pedido = new \WC_Order();

			( new MarketingConsent() )->on_field_value( self::CLAVE, $valor, 'other', $pedido );

			self::assertTrue( $this->prueba( $pedido )['value'] );
		}

		/** @return array<string, array{0: mixed}> */
		public static function valoresAfirmativos(): array {
			return array(
				'json bool'   => array( true ),
				'form-data 1' => array( '1' ),
				'entero 1'    => array( 1 ),
				'texto true'  => array( 'true' ),
			);
		}

		/** @testdox [NEG] Otra clave (akibara/rut) no escribe nada. */
		public function test_otra_clave_no_escribe(): void {
			$pedido = new \WC_Order();

			( new MarketingConsent() )->on_field_value( 'akibara/rut', '12.345.678-5', 'billing', $pedido );

			self::assertSame( '', $pedido->get_meta( MarketingConsent::META_KEY, true ) );
			self::assertSame( array(), $pedido->akb_notes );
		}

		/** @testdox [NEG] Un WC_Customer no escribe nada: la prueba vive en el pedido. */
		public function test_un_cliente_no_escribe(): void {
			$cliente = new \WC_Customer();

			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $cliente );

			self::assertSame( array(), $cliente->akb_meta );
		}

		/**
		 * CAMBIO DE CONTRATO DELIBERADO (2026-09-11). Hasta hoy el admin no
		 * escribía NADA, y eso dejaba la prueba contradiciendo en silencio a la
		 * meta que el proveedor lee para sincronizar el estado suscrito
		 * (WooCommerce guarda la casilla del metabox por el mismo hook). La
		 * prueba sigue intacta —una edición del panel no es consentimiento del
		 * cliente— pero el pedido recibe una nota firmada por el usuario del
		 * panel que dice a qué cambió la casilla.
		 *
		 * @testdox Una edición desde el admin no toca la prueba, pero deja nota del cambio firmada por el panel.
		 */
		public function test_el_admin_no_toca_la_prueba_pero_anota_el_cambio(): void {
			$modulo = new MarketingConsent();
			$pedido = new \WC_Order();

			// El cliente pagó sin marcar: prueba «NO» y meta de WooCommerce en '0'.
			$modulo->on_field_value( self::CLAVE, false, 'other', $pedido );
			$pedido->update_meta_data( '_wc_other/' . self::CLAVE, '0' );
			$prueba_original = $pedido->get_meta( MarketingConsent::META_KEY, true );

			// El admin marca la casilla en el metabox y actualiza.
			$this->admin = true;
			$modulo->on_field_value( self::CLAVE, '1', 'other', $pedido );

			self::assertSame( $prueba_original, $pedido->get_meta( MarketingConsent::META_KEY, true ), 'La prueba del checkout no cambia.' );
			self::assertCount( 2, $pedido->akb_notes );
			self::assertSame(
				'Casilla de ofertas y novedades cambiada desde el panel: ahora SÍ. No es consentimiento del cliente: la prueba del checkout queda como estaba.',
				$pedido->akb_notes[1]
			);
			self::assertSame( array( 0, true ), $pedido->akb_note_flags[1], 'Nota privada y firmada por el usuario del panel.' );
		}

		/**
		 * El metabox reenvía TODOS los campos en cada «Actualizar»: un guardado
		 * sin tocar la casilla no puede dejar nota.
		 *
		 * @testdox [NEG] Guardar el pedido desde el admin sin cambiar la casilla no deja nota.
		 */
		public function test_el_admin_sin_cambiar_la_casilla_no_anota(): void {
			$this->admin = true;
			$modulo      = new MarketingConsent();

			// Pedido viejo sin meta de WooCommerce: el metabox manda '0' → igual que «nada».
			$pedido = new \WC_Order();
			$modulo->on_field_value( self::CLAVE, '0', 'other', $pedido );
			self::assertSame( array(), $pedido->akb_notes );
			self::assertSame( '', $pedido->get_meta( MarketingConsent::META_KEY, true ), 'El admin nunca escribe la prueba.' );

			// Pedido con la casilla ya en '1': el metabox reenvía '1'.
			$pedido = new \WC_Order( array( '_wc_other/' . self::CLAVE => '1' ) );
			$modulo->on_field_value( self::CLAVE, '1', 'other', $pedido );
			self::assertSame( array(), $pedido->akb_notes );
		}

		/**
		 * El hook corre en cada PUT y POST de /checkout. Sin esto, cada tecla
		 * en el checkout dejaría una nota más en el pedido.
		 *
		 * @testdox Repetir la misma decisión no reescribe ni vuelve a anotar.
		 */
		public function test_la_misma_decision_no_se_repite(): void {
			$pedido = new \WC_Order();
			$modulo = new MarketingConsent();

			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			$primera = $pedido->get_meta( MarketingConsent::META_KEY, true );
			$modulo->on_field_value( self::CLAVE, '1', 'other', $pedido );

			self::assertSame( $primera, $pedido->get_meta( MarketingConsent::META_KEY, true ) );
			self::assertCount( 1, $pedido->akb_notes );
		}

		/** @testdox Cambiar de decisión antes de pagar deja una segunda nota, la vigente. */
		public function test_cambiar_de_decision_anota_la_nueva(): void {
			$pedido = new \WC_Order();
			$modulo = new MarketingConsent();

			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			$modulo->on_field_value( self::CLAVE, false, 'other', $pedido );

			self::assertFalse( $this->prueba( $pedido )['value'] );
			self::assertCount( 2, $pedido->akb_notes );
			self::assertStringStartsWith( 'Consentimiento de ofertas y novedades: NO', $pedido->akb_notes[1] );
		}

		/** @testdox Sin página de política, la nota lo dice en vez de inventar una fecha. */
		public function test_sin_politica_la_nota_lo_dice(): void {
			Functions\when( 'get_option' )->justReturn( 0 );
			$pedido = new \WC_Order();

			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );

			self::assertSame( '', $this->prueba( $pedido )['policy_modified_gmt'] );
			self::assertStringEndsWith( 'Política vigente del sin fecha registrada.', $pedido->akb_notes[0] );
		}

		/**
		 * S-69a9a4ee (2026-09-19): una política editada de noche en Chile queda
		 * en `post_modified_gmt` con la fecha del día SIGUIENTE (Chile va detrás
		 * de GMT). Leer la fecha directo del string GMT —lo que hacía el
		 * `preg_match` anterior— le ponía a la nota un día que en Santiago
		 * nunca fue: la política se editó el 14, la nota decía 15.
		 *
		 * @testdox La fecha de la nota es la de Chile, no la de GMT.
		 */
		public function test_la_nota_usa_la_fecha_de_chile_no_la_de_gmt(): void {
			// 2026-09-15 02:15 GMT = 2026-09-14 22:15 en Chile (UTC-4): la
			// política se guardó pasadas las 22:00 hora local, un caso real
			// que el propio hallazgo describe.
			Functions\when( 'get_post_field' )->alias(
				static fn ( string $field, int $post_id ) => 'post_modified_gmt' === $field && 23640 === $post_id ? '2026-09-15 02:15:00' : ''
			);

			$pedido = new \WC_Order();
			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );

			self::assertStringContainsString(
				'Política vigente del 14/09/2026.',
				$pedido->akb_notes[0],
				'GMT dice 15; en Santiago, a esa hora, seguía siendo 14.'
			);
		}

		/**
		 * La etiqueta NUNCA se lee de la option del proveedor: se lee de lo que
		 * WooCommerce registró, y si el campo no está registrado queda vacía.
		 *
		 * @testdox Sin el campo en el registro de WooCommerce, la etiqueta queda vacía y la prueba se guarda igual.
		 */
		public function test_sin_registro_la_etiqueta_queda_vacia(): void {
			$GLOBALS['akibara_test_checkout_fields'] = array();
			$pedido                                  = new \WC_Order();

			( new MarketingConsent() )->on_field_value( self::CLAVE, true, 'other', $pedido );

			self::assertSame( '', $this->prueba( $pedido )['label'] );
			self::assertStringContainsString( 'Texto que vio el cliente: “”', $pedido->akb_notes[0] );
		}

		/** @testdox La clave del campo se cambia por filtro, sin tocar el módulo. */
		public function test_la_clave_se_cambia_por_filtro(): void {
			Functions\when( 'apply_filters' )->alias(
				static fn ( string $hook, $value ) => 'akibara_marketing_consent_field_key' === $hook ? 'otro/proveedor_opt_in' : $value
			);
			$pedido = new \WC_Order();
			$modulo = new MarketingConsent();

			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertSame( '', $pedido->get_meta( MarketingConsent::META_KEY, true ), 'La clave de Brevo ya no cuenta.' );

			$modulo->on_field_value( 'otro/proveedor_opt_in', true, 'other', $pedido );
			self::assertTrue( $this->prueba( $pedido )['value'] );
		}

		/**
		 * R6-consentimiento-sin-tocar (2026-09-19). El checkout de bloques sólo
		 * manda en `additional_fields` los campos que el cliente tocó: la
		 * casilla sin marcar no viaja, WooCommerce no dispara el hook del campo
		 * y el pedido cerraba sin prueba (medido en el espejo: el POST llevaba
		 * sólo el RUT; en producción ningún pedido llevó jamás la prueba). Al
		 * final de `update_order_from_request`, sin prueba y con el campo
		 * registrado, la casilla se dejó sin marcar: prueba «NO» con el texto
		 * que el cliente vio, y ningún aviso después.
		 *
		 * @testdox La casilla que el bloque no envía queda como «NO» al cerrar el pedido, sin aviso.
		 */
		public function test_la_casilla_que_no_viaja_queda_en_no(): void {
			$modulo = new MarketingConsent();
			$pedido = new \WC_Order();

			$modulo->on_order_from_request( $pedido );
			$modulo->warn_if_proof_missing( $pedido );

			$prueba = $this->prueba( $pedido );
			self::assertFalse( $prueba['value'] );
			self::assertSame( self::ETIQUETA_VISTA, $prueba['label'], 'El texto es el que el checkout pintó.' );
			self::assertSame( 'checkout-blocks', $prueba['source'] );
			self::assertSame(
				array( 'Consentimiento de ofertas y novedades: NO — casilla del checkout sin marcar. Texto: “' . self::ETIQUETA_VISTA . '”.' ),
				$pedido->akb_notes
			);
			self::assertSame( 0, $pedido->akb_saves, 'No llama a save(): el Store API guarda después.' );
			self::assertSame( array(), $this->warnings, 'Con la prueba escrita no hay nada que avisar.' );
		}

		/**
		 * Si la casilla SÍ viajó, `on_field_value` ya guardó la prueba antes de
		 * que corra el cierre: ni se pisa el «SÍ» ni se duplica la nota. Y una
		 * segunda vuelta (PUT + POST) tampoco.
		 *
		 * @testdox [NEG] Si la casilla viajó marcada, el cierre no la pisa ni repite la nota.
		 */
		public function test_si_la_casilla_viajo_el_cierre_no_la_pisa(): void {
			$modulo = new MarketingConsent();
			$pedido = new \WC_Order();

			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			$modulo->on_order_from_request( $pedido );
			$modulo->on_order_from_request( $pedido );

			self::assertTrue( $this->prueba( $pedido )['value'] );
			self::assertCount( 1, $pedido->akb_notes );
		}

		/**
		 * R6-consentimiento-sin-nota (2026-09-19). Con la casilla marcada, el
		 * bloque la guarda en la sesión con cada cambio, y al pulsar «Realizar
		 * el pedido» WooCommerce la copia al pedido recién construido ANTES de
		 * su primer `save()`: `on_field_value` corre con ID 0, la meta
		 * sobrevive pero `add_order_note()` devuelve 0 sin anotar, y la
		 * segunda pasada (ya con ID) ve la misma decisión y no repite. En el
		 * espejo: pedido #32080 con la prueba «SÍ» y sin nota. La nota queda
		 * pendiente y se escribe al cerrar `update_order_from_request`.
		 *
		 * @testdox La nota de un pedido que aún no tiene ID se escribe al cerrar, una sola vez.
		 */
		public function test_la_nota_del_pedido_aun_sin_id_se_escribe_al_cerrar(): void {
			$modulo         = new MarketingConsent();
			$pedido         = new \WC_Order();
			$pedido->akb_id = 0;

			// 1) Copia de la sesión al pedido recién construido (sin ID).
			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertTrue( $this->prueba( $pedido )['value'], 'La meta sí se escribe: sobrevive al primer save().' );
			self::assertSame( array(), $pedido->akb_notes, 'Sin ID no hay nota posible.' );

			// 2) WooCommerce guarda el borrador y vuelve a persistir el campo del POST.
			$pedido->akb_id = 32080;
			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );
			self::assertSame( array(), $pedido->akb_notes, 'La misma decisión no se repite.' );

			// 3) Cierre de update_order_from_request: la nota pendiente sale, y una sola vez.
			$modulo->on_order_from_request( $pedido );
			$modulo->on_order_from_request( $pedido );
			self::assertCount( 1, $pedido->akb_notes );
			self::assertStringStartsWith( 'Consentimiento de ofertas y novedades: SÍ', $pedido->akb_notes[0] );
			self::assertTrue( $this->prueba( $pedido )['value'], 'El cierre no pisa el «SÍ» con un «NO».' );
		}

		/**
		 * Revisión independiente H1 (2026-09-19): la copia de sesión (ID 0)
		 * dejó pendiente la nota de la decisión A; el POST trae la decisión B
		 * con el pedido ya guardado. Si la nota A saliera al cerrar, la ÚLTIMA
		 * nota del pedido contradiría la prueba, que dice B.
		 *
		 * @testdox Si la decisión cambia con el pedido ya guardado, la nota pendiente de la copia de sesión se descarta.
		 */
		public function test_una_decision_nueva_descarta_la_nota_pendiente(): void {
			$modulo         = new MarketingConsent();
			$pedido         = new \WC_Order();
			$pedido->akb_id = 0;

			$modulo->on_field_value( self::CLAVE, true, 'other', $pedido );   // sesión: SÍ, nota pendiente
			$pedido->akb_id = 32080;
			$modulo->on_field_value( self::CLAVE, false, 'other', $pedido );  // POST: NO, con ID
			$modulo->on_order_from_request( $pedido );

			self::assertFalse( $this->prueba( $pedido )['value'] );
			self::assertCount( 1, $pedido->akb_notes, 'Sólo la nota de la decisión vigente.' );
			self::assertStringStartsWith( 'Consentimiento de ofertas y novedades: NO', $pedido->akb_notes[0] );
		}

		/**
		 * Sin el campo en el registro de WooCommerce el checkout no pudo pintar
		 * la casilla: inventar un «NO» sería inventar un texto que nadie vio.
		 * Ahí no se escribe nada y el aviso lo dice.
		 *
		 * @testdox [NEG] Sin el campo registrado, el cierre no inventa un «NO» y el aviso dice que no está registrado.
		 */
		public function test_sin_el_campo_registrado_no_se_inventa_un_no(): void {
			$GLOBALS['akibara_test_checkout_fields'] = array();
			$modulo                                  = new MarketingConsent();
			$pedido                                  = new \WC_Order();

			$modulo->on_order_from_request( $pedido );
			$modulo->warn_if_proof_missing( $pedido );

			self::assertSame( '', $pedido->get_meta( MarketingConsent::META_KEY, true ) );
			self::assertSame( array(), $pedido->akb_notes );
			self::assertCount( 1, $this->warnings );
			self::assertStringContainsString( 'Campo «' . self::CLAVE . '» registrado en WooCommerce: no.', $this->warnings[0] );
		}

		/** @testdox [NEG] El cierre no escribe sobre un cliente ni sobre nada que no sea pedido. */
		public function test_el_cierre_solo_escribe_pedidos(): void {
			$modulo  = new MarketingConsent();
			$cliente = new \WC_Customer();

			$modulo->on_order_from_request( $cliente );
			$modulo->on_order_from_request( null );

			self::assertSame( array(), $cliente->akb_meta );
		}

		/**
		 * Si el proveedor renombra su campo, ningún pedido guarda la prueba y
		 * nadie se entera. La señal: al procesarse un pedido sin la meta, un
		 * warning POR PEDIDO en el log con la clave vigente. Hasta 1.116.29 se
		 * limitaba a uno al día con un transient que el object cache externo de
		 * producción perdía en cada purga de LiteSpeed (dos avisos con 16 h de
		 * diferencia el 2026-09-16) y que además escondía a cuántos pedidos
		 * alcanzaba.
		 *
		 * @testdox Cada pedido procesado sin la prueba deja su propio warning con la clave del campo y si está registrado.
		 */
		public function test_cada_pedido_sin_prueba_avisa(): void {
			$GLOBALS['akibara_test_checkout_fields'] = array();
			$modulo                                  = new MarketingConsent();

			$modulo->warn_if_proof_missing( new \WC_Order() );
			$modulo->warn_if_proof_missing( new \WC_Order() );

			self::assertCount( 2, $this->warnings, 'Un aviso por pedido, sin cuota diaria.' );
			self::assertStringContainsString( '#100', $this->warnings[0] );
			self::assertStringContainsString( 'Campo «' . self::CLAVE . '» registrado en WooCommerce: no.', $this->warnings[0] );
			self::assertStringContainsString( 'akibara_marketing_consent_field_key', $this->warnings[0] );
		}

		/** @testdox [NEG] Un pedido con la prueba no avisa; y un objeto que no es pedido tampoco. */
		public function test_un_pedido_con_prueba_no_avisa(): void {
			$modulo = new MarketingConsent();
			$pedido = new \WC_Order();
			$modulo->on_field_value( self::CLAVE, false, 'other', $pedido );

			$modulo->warn_if_proof_missing( $pedido );
			$modulo->warn_if_proof_missing( new \WC_Customer() );
			$modulo->warn_if_proof_missing( null );

			self::assertSame( array(), $this->warnings );
		}

		/** @testdox register() engancha el hook del núcleo de WooCommerce con sus cuatro argumentos, el cierre del pedido y la señal de prueba ausente. */
		public function test_register_engancha_los_hooks(): void {
			$modulo = new MarketingConsent();

			Monkey\Actions\expectAdded( 'woocommerce_set_additional_field_value' )
				->once()
				->with( array( $modulo, 'on_field_value' ), 10, 4 );
			Monkey\Actions\expectAdded( 'woocommerce_store_api_checkout_update_order_from_request' )
				->once()
				->with( array( $modulo, 'on_order_from_request' ), 10, 1 );
			Monkey\Actions\expectAdded( 'woocommerce_store_api_checkout_order_processed' )
				->once()
				->with( array( $modulo, 'warn_if_proof_missing' ), 10, 1 );

			$modulo->register();
			$this->addToAssertionCount( 3 );
		}

		/**
		 * Cuenta construcciones en el composition root, como hace
		 * `AccountCollection\PluginWiringTest` con la autoridad de compras.
		 *
		 * @testdox El módulo queda cableado en Plugin::register_modules().
		 */
		public function test_el_modulo_queda_cableado_en_el_plugin(): void {
			$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );

			self::assertSame( 1, substr_count( $fuente, "['marketing_consent'] = new MarketingConsent()" ) );
			self::assertSame( 1, substr_count( $fuente, "['marketing_consent']->register()" ) );
		}
	}
}
