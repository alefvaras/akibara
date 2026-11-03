<?php
/**
 * El ajuste manual ligado a un pedido avisa antes de premiarlo dos veces.
 *
 * Pedido del dueño (2026-09-14). El ajuste manual queda para compensaciones y
 * correcciones —las historias de Instagram NO se premian, decidido el mismo
 * día—, y lo único que se automatiza es no repetirse sin darse cuenta: si el
 * pedido ya recibió puntos a mano, o si el pedido es de otro cliente, el ajuste
 * NO se asienta y pide confirmar. Confirmado, se asienta: la decisión sigue
 * siendo del dueño.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AjusteManualPorPedidoTest extends RewardsTestCase {

	private const CLIENTE = 7;
	private const PEDIDO  = 1234;

	/** @var array<int,object> Pedidos que devuelve wc_get_order(). */
	private array $pedidos = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_login' => 'qa.owner' ) );
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => self::CLIENTE === (int) $id ? (object) array( 'user_email' => 'Cliente@Correo.cl' ) : false
		);
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n, 0, ',', '.' ) );

		$this->pedidos = array(
			self::PEDIDO => $this->pedido( self::CLIENTE, 'otro@correo.cl' ),
			2000         => $this->pedido( 99, 'ajeno@correo.cl' ),
			3000         => $this->pedido( 0, 'cliente@correo.cl' ), // invitado con el correo del cliente
		);
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->pedidos[ (int) $id ] ?? false );
	}

	private function pedido( int $cliente, string $correo ): object {
		return new class( $cliente, $correo ) {
			public function __construct( private int $cliente, private string $correo ) {}
			public function get_id(): int {
				return 1;
			}
			public function get_customer_id(): int {
				return $this->cliente;
			}
			public function get_billing_email(): string {
				return $this->correo;
			}
		};
	}

	/**
	 * @return array{0:bool,1:string,2:int,3?:string}
	 */
	private function otorgar( int $puntos, string $motivo, int $pedido, string $confirmado = '' ): array {
		return ( new AdminAdjust() )->apply( self::CLIENTE, $puntos, 1, $motivo, $pedido, $confirmado );
	}

	/** @return array<string,mixed>|null */
	private function ultima_fila(): ?array {
		$filas = array_values( array_filter( $this->wpdb->inserts, static fn( $i ) => 'wp_akb_rewards_ledger' === $i[0] ) );
		return $filas ? $filas[ count( $filas ) - 1 ][1] : null;
	}

	/**
	 * @testdox El primer premio de un pedido se asienta ligado a ese pedido, no a la compra.
	 */
	public function test_el_primer_premio_queda_ligado_al_pedido(): void {
		$r = $this->otorgar( 400, 'Compensación por demora', self::PEDIDO );

		self::assertTrue( $r[0], (string) $r[1] );
		self::assertSame( 400, Ledger::balance( self::CLIENTE ) );
		$fila = $this->ultima_fila();
		self::assertSame( AdminAdjust::REF_PEDIDO, $fila['ref_type'] ?? null, 'Tipo propio: `order` lo concilia Accrual contra la compra.' );
		self::assertSame( self::PEDIDO, (int) ( $fila['ref_id'] ?? 0 ) );
		self::assertStringContainsString( 'pedido #1234', (string) ( $fila['note'] ?? '' ) );
	}

	/**
	 * @testdox Premiar otra vez el mismo pedido no se asienta: avisa cuándo, cuánto y por qué, y pide confirmar.
	 */
	public function test_repetir_el_pedido_pide_confirmacion(): void {
		$this->otorgar( 400, 'Compensación por demora', self::PEDIDO );

		$r = $this->otorgar( 300, 'Otra compensación', self::PEDIDO );

		self::assertFalse( $r[0], 'Sin confirmar no se premia dos veces.' );
		self::assertSame( AdminAdjust::AVISO_REPETIDO, $r[3] ?? '', 'El formulario tiene que saber QUÉ aviso falta confirmar.' );
		self::assertSame( 0, $r[2] );
		self::assertStringContainsString( '#1234', $r[1] );
		self::assertStringContainsString( '400', $r[1], 'El aviso dice cuánto se dio antes.' );
		self::assertStringContainsString( 'Compensación por demora', $r[1], 'Y por qué.' );
		self::assertSame( 400, Ledger::balance( self::CLIENTE ) );
	}

	/**
	 * @testdox Con la confirmación del dueño, el segundo premio sí se asienta.
	 */
	public function test_confirmado_se_otorga(): void {
		$this->otorgar( 400, 'Compensación por demora', self::PEDIDO );

		$r = $this->otorgar( 300, 'Segunda compensación, otro motivo', self::PEDIDO, AdminAdjust::AVISO_REPETIDO );

		self::assertTrue( $r[0], (string) $r[1] );
		self::assertSame( 700, Ledger::balance( self::CLIENTE ) );
	}

	/**
	 * @testdox Quitar puntos después de premiar no borra el aviso: el pedido igual ya fue premiado.
	 */
	public function test_quitar_no_tapa_el_aviso(): void {
		$this->otorgar( 400, 'Compensación por demora', self::PEDIDO );
		$quitar = ( new AdminAdjust() )->apply( self::CLIENTE, 100, -1, 'corrección', self::PEDIDO );
		self::assertTrue( $quitar[0], 'Quitar no pide confirmación.' );

		$r = $this->otorgar( 200, 'Otra compensación', self::PEDIDO );

		self::assertFalse( $r[0] );
		self::assertSame( AdminAdjust::AVISO_REPETIDO, $r[3] ?? '' );
	}

	/**
	 * @testdox Un pedido de otro cliente pide confirmar antes de asociarlo.
	 */
	public function test_pedido_ajeno_pide_confirmacion(): void {
		$r = $this->otorgar( 400, 'Compensación', 2000 );

		self::assertFalse( $r[0] );
		self::assertSame( AdminAdjust::AVISO_AJENO, $r[3] ?? '' );
		self::assertStringContainsString( 'no es de este cliente', $r[1] );
		self::assertSame( 0, Ledger::balance( self::CLIENTE ) );
	}

	/**
	 * @testdox Un pedido de invitado con el correo del cliente cuenta como suyo.
	 */
	public function test_pedido_de_invitado_con_su_correo_es_suyo(): void {
		$r = $this->otorgar( 400, 'Compensación', 3000 );

		self::assertTrue( $r[0], (string) $r[1] );
		self::assertSame( AdminAdjust::REF_PEDIDO, $this->ultima_fila()['ref_type'] ?? null );
		self::assertSame( 3000, (int) ( $this->ultima_fila()['ref_id'] ?? 0 ) );
	}

	/**
	 * @testdox Un pedido que no existe es un error, sin confirmación posible.
	 */
	public function test_pedido_inexistente_es_error(): void {
		$r = $this->otorgar( 400, 'Compensación', 555, AdminAdjust::AVISO_AJENO );

		self::assertFalse( $r[0] );
		self::assertSame( '', $r[3] ?? '', 'Confirmar no arregla un número equivocado.' );
		self::assertStringContainsString( 'No existe el pedido #555', $r[1] );
	}

	/**
	 * @testdox Sin pedido, el ajuste funciona exactamente como antes.
	 */
	public function test_sin_pedido_nada_cambia(): void {
		$r = ( new AdminAdjust() )->apply( self::CLIENTE, 400, 1, 'regalo' );

		self::assertTrue( $r[0] );
		self::assertSame( 'user', $this->ultima_fila()['ref_type'] ?? null );
		self::assertSame( self::CLIENTE, (int) ( $this->ultima_fila()['ref_id'] ?? 0 ) );
	}

	/**
	 * @testdox Un «%» en el motivo anterior no rompe el aviso (va como argumento, no como formato).
	 */
	public function test_un_porcentaje_en_el_motivo_no_rompe_el_aviso(): void {
		$this->otorgar( 400, 'Compensación del 50% del envío', self::PEDIDO );

		$r = $this->otorgar( 300, 'Otra', self::PEDIDO );

		self::assertStringContainsString( '50% del envío', $r[1] );
	}

	/**
	 * R1 de la segunda revisión: con los DOS avisos, confirmarlos se puede.
	 *
	 * @testdox Pedido ajeno y ya premiado: confirmando ambos avisos, el movimiento entra.
	 */
	public function test_los_dos_avisos_juntos_no_son_un_callejon_sin_salida(): void {
		$this->otorgar( 400, 'Compensación previa', 2000, AdminAdjust::AVISO_AJENO );

		$primero = $this->otorgar( 300, 'Otra', 2000, AdminAdjust::AVISO_AJENO );
		self::assertSame( AdminAdjust::AVISO_REPETIDO, $primero[3] ?? '', 'Precondición: falta el segundo aviso.' );

		// El formulario acumula: lo confirmado antes MÁS lo que falta.
		$acumulado = AdminAdjust::avisos_unidos( AdminAdjust::AVISO_AJENO, (string) $primero[3] );
		$segundo   = $this->otorgar( 300, 'Otra', 2000, $acumulado );

		self::assertTrue( $segundo[0], 'Con los dos avisos confirmados el ajuste se aplica: ' . $segundo[1] );
		self::assertSame( 'ajeno,repetido', $acumulado );
	}

	/**
	 * @testdox La confirmación exige los MISMOS avisos y la MISMA huella que dejó la pantalla.
	 */
	public function test_la_confirmacion_solo_vale_para_lo_que_se_pidio(): void {
		$huella = AdminAdjust::huella( 'cliente@correo.cl', 1234, 'mas', 400 );

		self::assertSame(
			'repetido',
			AdminAdjust::confirmacion_valida( 'repetido', $huella, 'repetido', $huella, $huella ),
			'Caso bueno: mismos avisos, misma huella.'
		);
		self::assertSame( '', AdminAdjust::confirmacion_valida( 'ajeno', $huella, 'repetido', $huella, $huella ), 'Confirmar otro aviso no vale.' );
		self::assertSame( '', AdminAdjust::confirmacion_valida( 'repetido', $huella, 'repetido', $huella, AdminAdjust::huella( 'cliente@correo.cl', 1243, 'mas', 400 ) ), 'Si cambia el pedido, no vale.' );
		self::assertSame( '', AdminAdjust::confirmacion_valida( 'repetido', 'otra', 'repetido', $huella, $huella ), 'Huella distinta, no vale.' );
		self::assertSame( '', AdminAdjust::confirmacion_valida( '', $huella, 'repetido', $huella, $huella ), 'Sin avisos, no vale.' );
		self::assertSame( '', AdminAdjust::confirmacion_valida( 'repetido', $huella, '', '', $huella ), 'Sin nada pendiente en el servidor, no vale.' );
	}

	/**
	 * @testdox Un borrador de caja se rechaza como borrador, no como pedido ajeno.
	 */
	public function test_borrador_de_caja_se_rechaza(): void {
		$this->pedidos[6000] = new class() {
			public function get_id(): int {
				return 6000;
			}
			public function get_type(): string {
				return 'shop_order';
			}
			public function get_customer_id(): int {
				return 7;
			}
			public function get_billing_email(): string {
				return 'cliente@correo.cl';
			}
			public function get_status(): string {
				return 'checkout-draft';
			}
		};

		$r = $this->otorgar( 400, 'Compensación', 6000 );

		self::assertFalse( $r[0] );
		self::assertStringContainsString( 'borrador de caja', $r[1] );
	}

	/**
	 * M1 de la primera revisión: confirmar un aviso no puede saltarse el otro.
	 *
	 * @testdox Confirmar que el pedido es ajeno NO salta el aviso de que ya fue premiado.
	 */
	public function test_confirmar_un_aviso_no_salta_el_otro(): void {
		// El pedido #2000 es de otro cliente y ya recibió puntos a mano.
		$this->otorgar( 400, 'Compensación previa', 2000, AdminAdjust::AVISO_AJENO );

		$r = $this->otorgar( 300, 'Otra', 2000, AdminAdjust::AVISO_AJENO );

		self::assertFalse( $r[0], 'Confirmar «ajeno» no autoriza premiarlo dos veces.' );
		self::assertSame( AdminAdjust::AVISO_REPETIDO, $r[3] ?? '' );
		self::assertStringContainsString( 'ya recibió', $r[1] );
	}

	/**
	 * M2 de la revisión: el doble envío se reconoce ANTES que el aviso del pedido.
	 *
	 * @testdox Reenviar el mismo ajuste dice «doble envío», no «ya recibió puntos».
	 */
	public function test_el_doble_envio_se_reconoce_como_tal(): void {
		$this->otorgar( 400, 'Compensación por demora', self::PEDIDO );

		$r = $this->otorgar( 400, 'Compensación por demora', self::PEDIDO );

		self::assertFalse( $r[0] );
		self::assertSame( '', $r[3] ?? '', 'Un doble envío no se confirma: se ignora.' );
		self::assertStringContainsString( 'doble envío', $r[1] );
	}

	/**
	 * M3 de la revisión: un reembolso no es un pedido de compra.
	 *
	 * @testdox Un reembolso se rechaza con su nombre, no como «pedido de otro cliente».
	 */
	public function test_un_reembolso_no_pasa_por_pedido(): void {
		$this->pedidos[4000] = new class() {
			public function get_id(): int {
				return 4000;
			}
			public function get_type(): string {
				return 'shop_order_refund';
			}
		};

		$r = $this->otorgar( 400, 'Compensación', 4000 );

		self::assertFalse( $r[0] );
		self::assertSame( '', $r[3] ?? '', 'No se puede confirmar lo que no es un pedido.' );
		self::assertStringContainsString( 'no es un pedido de compra', $r[1] );
	}

	/**
	 * @testdox Un pedido en la papelera se rechaza diciendo que está en la papelera.
	 */
	public function test_pedido_en_papelera_se_rechaza(): void {
		$this->pedidos[5000] = new class() {
			public function get_id(): int {
				return 5000;
			}
			public function get_type(): string {
				return 'shop_order';
			}
			public function get_customer_id(): int {
				return 7;
			}
			public function get_billing_email(): string {
				return 'cliente@correo.cl';
			}
			public function get_status(): string {
				return 'trash';
			}
		};

		$r = $this->otorgar( 400, 'Compensación', 5000 );

		self::assertFalse( $r[0] );
		self::assertStringContainsString( 'papelera', $r[1] );
	}

	/**
	 * La confirmación va atada a los valores revisados: si el dueño corrige el
	 * número o el correo, deja de valer.
	 *
	 * @testdox La huella cambia con el correo, el pedido, el signo o los puntos.
	 */
	public function test_la_huella_ata_la_confirmacion_a_los_valores(): void {
		$base = AdminAdjust::huella( 'cliente@correo.cl', 1234, 'mas', 400 );

		self::assertSame( $base, AdminAdjust::huella( 'Cliente@Correo.cl ', 1234, 'mas', 400 ), 'El correo no distingue mayúsculas ni espacios.' );
		self::assertNotSame( $base, AdminAdjust::huella( 'otro@correo.cl', 1234, 'mas', 400 ) );
		self::assertNotSame( $base, AdminAdjust::huella( 'cliente@correo.cl', 1243, 'mas', 400 ) );
		self::assertNotSame( $base, AdminAdjust::huella( 'cliente@correo.cl', 1234, 'menos', 400 ) );
		self::assertNotSame( $base, AdminAdjust::huella( 'cliente@correo.cl', 1234, 'mas', 500 ) );
	}

	/**
	 * @testdox Un motivo larguísimo no se lleva por delante el pedido ni parte un carácter.
	 */
	public function test_un_motivo_larguisimo_conserva_el_pedido(): void {
		$this->otorgar( 400, str_repeat( 'á', 300 ), self::PEDIDO );

		$nota = (string) ( $this->ultima_fila()['note'] ?? '' );
		self::assertLessThanOrEqual( 255, strlen( $nota ), 'La columna admite 255 bytes.' );
		self::assertStringEndsWith( '(pedido #1234)', $nota, 'El pedido no puede perderse por un motivo largo.' );
		self::assertSame( $nota, mb_convert_encoding( $nota, 'UTF-8', 'UTF-8' ), 'Ningún carácter partido a la mitad.' );
	}
}
