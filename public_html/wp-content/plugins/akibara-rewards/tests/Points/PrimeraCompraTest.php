<?php
/**
 * Contrato de la primera compra: bono de bienvenida, premio al padrino y canje.
 *
 * Decisiones del dueño (2026-09-13), tras la asesoría con datos de producción,
 * seis plugins de WordPress.org y Dame Mi Manga:
 *   1. Bono y padrino en la primera compra pagada con dinero, SIN monto
 *      mínimo («no es muy honesto con el programa»; queda configurable),
 *      aunque «Dar puntos por compra» esté apagado. Al padrino, cuando el
 *      pedido pasa a Completado; si se anula o se reembolsa entero, se le
 *      descuenta.
 *   2. Uno por persona: RUT, teléfono y correo contra compras anteriores.
 *   3. Canje sólo en carritos desde $50.000, como Dame Mi Manga.
 *
 * También fija las tres roturas que tumbaron el primer intento (rewards
 * 0.3.15): el correo «sumaste 0», el pedido de cero sellado para siempre por el
 * bono y el premio al padrino imposible de revertir.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\FirstPurchase;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class PrimeraCompraTest extends RewardsTestCase {

	/** @var array<string,mixed> Options vivas de la prueba. */
	private array $opciones = array();

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	/** @var array<int,array<string,mixed>> Consultas recibidas por wc_get_orders. */
	private array $consultas = array();

	/** @var callable|null Decide qué devuelve wc_get_orders para una consulta. */
	private $historia = null;

	/** @var array<int,\WP_User> */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();

		$caso = $this;

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $caso->opcion( $option, $default_value )
		);
		Functions\when( 'wc_get_order' )->alias(
			static fn( int $id ) => $caso->pedido( $id )
		);
		Functions\when( 'wc_get_orders' )->alias(
			static fn( array $args ): array => $caso->buscar_pedidos( $args )
		);
		Functions\when( 'get_user_by' )->alias(
			static fn( string $by, $id ) => $caso->usuario( (int) $id )
		);
	}

	/** @param mixed $defecto */
	public function opcion( string $nombre, $defecto ) {
		return array_key_exists( $nombre, $this->opciones ) ? $this->opciones[ $nombre ] : $defecto;
	}

	/** @return \WC_Order|false */
	public function pedido( int $id ) {
		return $this->orders[ $id ] ?? false;
	}

	/**
	 * @param array<string,mixed> $args Consulta.
	 * @return array<int,int>
	 */
	public function buscar_pedidos( array $args ): array {
		$this->consultas[] = $args;
		return null === $this->historia ? array() : ( $this->historia )( $args );
	}

	/** @return \WP_User|false */
	public function usuario( int $id ) {
		return $this->users[ $id ] ?? false;
	}

	/** Pedido pagado, con identidad, registrado en el mapa. */
	private function compra( int $id, int $cliente, float $neto, float $iva, string $rut = '12.345.678-5', string $telefono = '+56912345678', string $correo = 'camila@example.com' ): \WC_Order {
		$order                               = $this->make_order( $id, $cliente, $neto, $iva );
		$order->billing_email                = $correo;
		$order->billing_phone                = $telefono;
		$order->meta[ FirstPurchase::META_RUT ] = $rut;
		$this->orders[ $id ]                 = $order;

		return $order;
	}

	// ── 1. Bono de bienvenida ──────────────────────────────────────────────

	/** @testdox El bono se paga aunque la compra no dé puntos, y el correo no dice «sumaste 0». */
	public function test_el_bono_se_paga_aunque_la_compra_no_de_puntos(): void {
		$this->opciones['akb_rewards_earn_enabled'] = 'no';
		$order = $this->compra( 100, 7, 10000.0, 1900.0 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_after_order_award' )->once()->with( $order, 7, 0 );
		( new Accrual() )->maybe_award( 100 );

		$this->assertSame( 0, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertArrayHasKey( Accrual::META_RATE, $order->saved_meta, 'La tasa se sella también en la rama de cero.' );

		( new WelcomeBonus() )->maybe_pay( $order, 7, 0 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( 500, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 500, $order->saved_meta[ Accrual::META_WELCOME_BONUS ] );
	}

	/** @testdox El pedido de cero con bono sigue acreditando la compra si se vuelven a encender los puntos. */
	public function test_el_bono_no_sella_el_pedido_para_siempre(): void {
		$this->opciones['akb_rewards_earn_enabled'] = 'no';
		$order = $this->compra( 100, 7, 10000.0, 1900.0 );
		( new Accrual() )->maybe_award( 100 );
		( new WelcomeBonus() )->maybe_pay( $order, 7, 0 );

		unset( $this->opciones['akb_rewards_earn_enabled'] );
		( new Accrual() )->maybe_award( 100 );

		$this->assertSame( 738, Ledger::balance( 7 ), '500 de bono + 238 de la compra (2 por cada $100 de $11.900).' );
		$this->assertSame( 738, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 500, $order->saved_meta[ Accrual::META_WELCOME_BONUS ] );
	}

	/** @testdox Sin mínimo: cualquier primera compra pagada con dinero da el bono. */
	public function test_sin_minimo_cualquier_compra_con_dinero_da_bono(): void {
		$order = $this->compra( 100, 7, 832.0, 158.0 ); // Un llavero de $990.

		( new WelcomeBonus() )->maybe_pay( $order, 7, 20 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox Si el dueño pone una compra mínima en el panel, se respeta. */
	public function test_la_compra_minima_se_lee_del_panel(): void {
		$this->opciones['akb_rewards_first_purchase_min'] = 10000;
		$order = $this->compra( 100, 7, 8000.0, 1520.0 ); // $9.520.

		( new WelcomeBonus() )->maybe_pay( $order, 7, 190 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( WelcomeBonus::META_WELCOME_PAID, $this->wpdb->user_meta[7] ?? array() );
		$this->assertStringContainsString( 'mínimo', implode( ' ', $order->notes ), 'El pedido deja dicho por qué no hubo bono.' );
	}

	/** @testdox Una compra pagada entera con puntos no da bono. */
	public function test_una_compra_pagada_con_puntos_no_da_bono(): void {
		$this->opciones['akb_rewards_first_purchase_min'] = 0;
		$order = $this->compra( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Redemption::META_REDEEMED_VALUE ]  = 11900;
		$order->meta[ Redemption::META_REDEEMED_POINTS ] = 11900;

		( new WelcomeBonus() )->maybe_pay( $order, 7, 0 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * @testdox Uno por persona: si la señal ya aparece en una compra anterior, no hay bono ($señal).
	 * @dataProvider senales
	 */
	public function test_uno_por_persona( string $senal ): void {
		$this->historia = static function ( array $args ) use ( $senal ): array {
			$coincide = array(
				'rut'    => in_array( '12.345.678-5', (array) ( $args['meta_query'][0]['value'] ?? array() ), true ),
				'phone'  => '+56912345678' === ( $args['billing_phone'] ?? '' ),
				'email'  => 'camila@example.com' === ( $args['billing_email'] ?? '' ),
				'cuenta' => 7 === ( $args['customer_id'] ?? 0 ),
			);
			return $coincide[ $senal ] ? array( 55 ) : array();
		};

		// Otra cuenta (8) con la misma persona detrás; en «cuenta», la misma.
		$cliente = 'cuenta' === $senal ? 7 : 8;
		$order   = $this->compra( 100, $cliente, 10000.0, 1900.0 );

		( new WelcomeBonus() )->maybe_pay( $order, $cliente, 238 );

		$this->assertSame( 0, Ledger::balance( $cliente ) );
	}

	/** @return array<string,array{0:string}> */
	public static function senales(): array {
		return array(
			'mismo RUT'      => array( 'rut' ),
			'mismo teléfono' => array( 'phone' ),
			'mismo correo'   => array( 'email' ),
			'misma cuenta'   => array( 'cuenta' ),
		);
	}

	/** @testdox Una señal apagada en el panel no se mira. */
	public function test_una_senal_apagada_no_se_mira(): void {
		$this->opciones['akb_rewards_first_purchase_by_phone'] = 'no';
		$this->historia = static fn( array $args ): array => '+56912345678' === ( $args['billing_phone'] ?? '' ) ? array( 55 ) : array();

		$order = $this->compra( 100, 8, 10000.0, 1900.0 );
		( new WelcomeBonus() )->maybe_pay( $order, 8, 238 );

		$this->assertSame( 500, Ledger::balance( 8 ) );
		$this->assertEmpty( array_filter( $this->consultas, static fn( array $c ): bool => isset( $c['billing_phone'] ) ) );
	}

	/** @testdox Las compras anteriores se buscan pagadas, antes de este pedido y desde el inicio del programa. */
	public function test_la_busqueda_de_compras_anteriores_esta_acotada(): void {
		$order = $this->compra( 100, 7, 10000.0, 1900.0 );

		FirstPurchase::has_prior_purchase( $order );

		$this->assertNotEmpty( $this->consultas );
		foreach ( $this->consultas as $consulta ) {
			$this->assertSame( \Akibara\Rewards\Support\OrderStates::PAID, $consulta['status'] );
			$this->assertSame( array( 100 ), $consulta['exclude'] );
			$this->assertSame( 1, $consulta['limit'] );
			$this->assertMatchesRegularExpression( '/^\d+\.\.\.\d+$/', $consulta['date_created'] );
			list( $desde, $hasta ) = array_map( 'intval', explode( '...', $consulta['date_created'] ) );
			$this->assertSame( $order->get_date_created()->getTimestamp() - 1, $hasta );
			$this->assertLessThan( $hasta, $desde );
		}
	}

	/** @testdox Teléfono y RUT se buscan en las formas en que pueden estar guardados. */
	public function test_variantes_de_telefono_y_rut(): void {
		$this->assertSame( array( '+56912345678', '912345678', '56912345678' ), FirstPurchase::phone_variants( '+56 9 1234 5678' ) );
		$this->assertSame( FirstPurchase::phone_variants( '912345678' ), FirstPurchase::phone_variants( '+56912345678' ) );
		$this->assertSame( array(), FirstPurchase::phone_variants( '1234' ), 'Menos de 8 dígitos no identifica a nadie.' );

		$this->assertSame(
			array( '12.345.678-5', '12345678-5', '123456785', '012.345.678-5', '012345678-5', '0123456785' ),
			FirstPurchase::rut_variants( '123456785' ),
			'Con y sin el cero a la izquierda que el checkout guarda tal cual.'
		);
		$this->assertContains( '9.876.543-K', FirstPurchase::rut_variants( '9876543k' ) );
		$this->assertContains( '9.876.543-k', FirstPurchase::rut_variants( '9.876.543-K' ) );
		$this->assertSame( array(), FirstPurchase::rut_variants( 'no-es-rut' ) );
	}

	// ── 2. Padrino ─────────────────────────────────────────────────────────

	/** Invitado 42 de la cuenta 7, con su compra registrada. */
	private function invitado( float $neto = 10000.0, float $iva = 1900.0 ): \WC_Order {
		$u             = new \WP_User( 7 );
		$u->user_email = 'padrino@example.com';
		$this->users[7] = $u;
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;

		return $this->compra( 100, 42, $neto, $iva, '9.876.543-K', '+56987654321', 'invitado@example.com' );
	}

	/** @testdox Al padrino no se le paga en «Listo para enviar»: se le paga en «Completado». */
	public function test_el_padrino_cobra_en_completado(): void {
		$order     = $this->invitado();
		$referrals = new Referrals();

		$referrals->on_status_changed( 100, 'processing', 'shipping-progress', $order );
		$this->assertSame( 0, Ledger::balance( 7 ), 'En «Listo para enviar» todavía no.' );

		$referrals->on_status_changed( 100, 'shipping-progress', 'completed', $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox El estado que paga al padrino se lee del panel. */
	public function test_el_estado_del_padrino_se_lee_del_panel(): void {
		$this->opciones['akb_rewards_referral_status'] = 'shipping-progress';
		$order = $this->invitado();

		( new Referrals() )->on_status_changed( 100, 'processing', 'shipping-progress', $order );

		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox Con una compra mínima puesta en el panel, bajo ella el padrino no cobra. */
	public function test_bajo_la_compra_minima_el_padrino_no_cobra(): void {
		$this->opciones['akb_rewards_first_purchase_min'] = 10000;
		$order = $this->invitado( 8000.0, 1520.0 );

		( new Referrals() )->on_status_changed( 100, 'shipping-progress', 'completed', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/** @testdox Invitarse a uno mismo con otra cuenta y el mismo RUT no paga. */
	public function test_el_invitado_no_puede_ser_el_mismo_padrino(): void {
		$order = $this->invitado();
		$this->wpdb->user_meta[7]['billing_rut'] = '9876543-k';

		( new Referrals() )->on_status_changed( 100, 'shipping-progress', 'completed', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/** @testdox La reversa del padrino corre aunque el pedido no haya dado puntos. */
	public function test_la_reversa_del_padrino_no_depende_de_los_puntos_del_pedido(): void {
		$this->opciones['akb_rewards_earn_enabled'] = 'no';
		$order     = $this->invitado();
		$referrals = new Referrals();

		$referrals->on_status_changed( 100, 'shipping-progress', 'completed', $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$referrals->on_status_changed( 100, 'completed', 'cancelled', $order );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Venta anulada = premio descontado, dé o no puntos el pedido.' );
	}

	/** @testdox Un reembolso total descuenta el premio del padrino; uno parcial no. */
	public function test_el_reembolso_total_descuenta_al_padrino(): void {
		$order     = $this->invitado();
		$referrals = new Referrals();
		$referrals->on_status_changed( 100, 'shipping-progress', 'completed', $order );

		$order->refunds[] = new \WC_Order_Refund( array(), 2000.0 );
		$referrals->on_order_refunded( 100, 1 );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Parcial: el premio se queda.' );

		$order->refunds[] = new \WC_Order_Refund( array(), 9900.0 );
		$referrals->on_order_refunded( 100, 2 );
		$this->assertSame( 0, Ledger::balance( 7 ), 'Total: se descuenta.' );
	}

	/** @testdox Si la reversa no recupera el premio completo, el invitado NO vuelve a ser elegible. */
	public function test_una_reversa_clampada_no_reabre_al_padrino(): void {
		$order     = $this->invitado();
		$referrals = new Referrals();
		$referrals->on_status_changed( 100, 'shipping-progress', 'completed', $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// El padrino ya gastó parte del premio: la reversa sólo recupera 200.
		Ledger::debit( 7, 300, 'redemption', 'order', 999, 'Canje', false, 'canje_qa' );
		$this->assertSame( 200, Ledger::balance( 7 ) );

		$referrals->on_status_changed( 100, 'completed', 'cancelled', $order );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Se recupera lo que hay, nunca bajo cero.' );
		$this->assertSame( 100, (int) ( $this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] ?? 0 ), 'La marca se queda: sin recuperar el premio no hay segunda oportunidad.' );
		$this->assertStringContainsString( 'no se recuperó completo', implode( ' ', $order->notes ) );

		// Y la siguiente compra del invitado NO vuelve a pagar.
		$otro = $this->compra( 101, 42, 12000.0, 2280.0, '9.876.543-K', '+56987654321', 'invitado@example.com' );
		$referrals->on_status_changed( 101, 'shipping-progress', 'completed', $otro );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Un premio no recuperado no se paga dos veces.' );
	}

	/** @testdox Un RUT con cero a la izquierda se reconoce igual. */
	public function test_el_rut_con_cero_a_la_izquierda_se_reconoce(): void {
		$formas = FirstPurchase::rut_variants( '12.345.678-5' );

		$this->assertContains( '012.345.678-5', $formas, 'El checkout guarda el cero que el cliente teclea.' );
		$this->assertContains( '012345678-5', $formas );
		$this->assertSame( FirstPurchase::normalize_rut( '012.345.678-5' ), FirstPurchase::normalize_rut( '12345678-5' ) );
	}

	/** @testdox Un pedido sin fecha sigue mirando sólo desde el inicio del programa. */
	public function test_sin_fecha_la_busqueda_no_barre_toda_la_historia(): void {
		$order               = $this->compra( 100, 7, 10000.0, 1900.0 );
		$order->date_created = null;

		FirstPurchase::has_prior_purchase( $order );

		foreach ( $this->consultas as $consulta ) {
			$this->assertMatchesRegularExpression( '/^>\d+$/', (string) $consulta['date_created'], 'Sin fecha se conserva el piso del programa.' );
		}
	}

	// ── 3. Correo ──────────────────────────────────────────────────────────

	/** @testdox El correo de un bono sin puntos de compra no dice «sumaste 0»; sin nada, no hay correo. */
	public function test_el_correo_del_bono_solo_no_dice_sumaste_cero(): void {
		$wc = $this->install_fake_wc();
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$order                = $this->compra( 100, 7, 10000.0, 1900.0 );
		$order->billing_email = 'camila@example.com';
		$order->meta[ Accrual::META_EARNED ]        = 500;
		$order->meta[ Accrual::META_WELCOME_BONUS ] = 500;

		( new Emails() )->send_order_email( $order, 7, 0 );

		$this->assertCount( 1, $wc->the_mailer->sent );
		$correo = $wc->the_mailer->sent[0];
		$this->assertStringContainsString( 'bienvenida', $correo['subject'] );
		$this->assertStringContainsString( '500', $correo['subject'] );
		$this->assertStringNotContainsString( 'sumaste <strong>0', $correo['body'] );
		$this->assertStringContainsString( 'bono de bienvenida de 500 puntos', $correo['body'] );

		$this->assertStringContainsString( 'desde $50.000', $correo['body'], 'El correo que empuja a canjear dice desde qué monto se puede.' );

		$vacio = $this->compra( 101, 7, 10000.0, 1900.0 );
		( new Emails() )->send_order_email( $vacio, 7, 0 );
		$this->assertCount( 1, $wc->the_mailer->sent, 'Cero de compra y cero de bono: no hay nada que contar.' );
	}

	// ── 4. Señalización ────────────────────────────────────────────────────

	/** @testdox La página del programa dice las reglas nuevas con las cifras del panel. */
	public function test_la_pagina_del_programa_dice_las_reglas_nuevas(): void {
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $numero ): string => number_format( (float) $numero, 0, ',', '.' )
		);
		Functions\when( 'shortcode_atts' )->alias(
			static fn( array $pares, $atts ): array => array_merge( $pares, is_array( $atts ) ? $atts : array() )
		);

		$pagina = new \Akibara\Rewards\Points\ProgramPage();

		$canje = \Akibara\Rewards\Points\ProgramPage::redeem_sentence();
		$this->assertStringContainsString( '$50.000', $canje, 'El carrito mínimo se dice donde el cliente lee las reglas.' );

		$tabla = $pagina->render( array() );
		$this->assertStringContainsString( 'uno por persona', $tabla );
		$this->assertStringContainsString( 'recibe su primera compra', $tabla, 'El premio al padrino se paga al entregarse.' );

		$this->assertSame( '$50.000', $pagina->render( array( 'solo' => 'minimo_canje' ) ) );
		$this->assertSame( 'cualquier monto', $pagina->render( array( 'solo' => 'minimo_primera_compra' ) ), 'Sin mínimo, la página no inventa una cifra.' );

		$this->opciones['akb_rewards_first_purchase_min'] = 10000;
		$this->assertSame( '$10.000', $pagina->render( array( 'solo' => 'minimo_primera_compra' ) ) );
	}

	// ── 5. Canje ───────────────────────────────────────────────────────────

	/** @testdox Bajo el carrito mínimo no se canjea; desde el mínimo, sí. */
	public function test_carrito_minimo_para_canjear(): void {
		$wc = $this->install_fake_wc();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 3000;
		Redemption::set_intent( 1000 );

		$chico           = new \WC_Cart();
		$chico->contents = array( array( 'line_total' => 30000.0, 'line_tax' => 5700.0 ) ); // $35.700.
		$this->assertSame( 0, Redemption::effective_points( $chico ) );
		$this->assertSame( 14300, Redemption::missing_for_minimum( 35700.0 ) );

		$justo           = new \WC_Cart();
		$justo->contents = array( array( 'line_total' => 42017.0, 'line_tax' => 7983.0 ) ); // $50.000.
		$this->assertSame( 1000, Redemption::effective_points( $justo ) );
		$this->assertSame( 0, Redemption::missing_for_minimum( 50000.0 ) );
		$this->assertSame( 1, Redemption::missing_for_minimum( 49999.5 ), 'Un peso que falte ya no alcanza.' );

		$this->opciones['akb_rewards_redeem_min_cart'] = 0;
		$this->assertSame( 1000, Redemption::effective_points( $chico ), 'Con 0 en el panel no hay mínimo.' );

		unset( $wc );
	}
}
