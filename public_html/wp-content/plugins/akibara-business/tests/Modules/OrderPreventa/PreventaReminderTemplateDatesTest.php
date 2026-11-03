<?php
/**
 * Qué FECHA ve el cliente en el correo de preventa.
 *
 * El módulo maneja dos fechas que se parecen y no son lo mismo:
 *
 *   · la de la EDITORIAL (`_akb_eta_release_date`): cuándo publica el tomo.
 *   · la de ARRIBO (`ORDER_SUMMARY_MAX`): cuándo llega a la bodega y se puede
 *     despachar. Es la única que el cliente ve al comprar, en su pedido y en el
 *     recordatorio T-7.
 *
 * Hasta 1.114.10 las ramas «cambio de fecha» y «ya tenemos fecha» imprimían la
 * de la editorial bajo una etiqueta de «estimada», y el arribo — que ya llegaba
 * al template en `$summary_max_ymd` — se descartaba. Como el envío desde la
 * editorial tarda semanas, una postergación de 21 días salía como una fecha
 * ANTERIOR a la ya prometida: un aviso de retraso que se leía como un adelanto.
 * Medido en el espejo: compra prometiendo el 13-nov, la editorial mueve del
 * 17-oct al 7-nov, el arribo real pasa al 14-dic, y el correo decía «Fecha
 * nueva estimada: 7 de noviembre».
 *
 * Estas pruebas renderizan los templates DE VERDAD y exigen que el arribo
 * aparezca. Vuelven a rojo con la versión anterior.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\PedidoConDatosDeCliente;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class PreventaReminderTemplateDatesTest extends TestCase {

	/** Lanzamiento previo de la editorial. */
	private const EDITORIAL_VIEJA = '2026-10-17';

	/** Lanzamiento nuevo tras la postergación. */
	private const EDITORIAL_NUEVA = '2026-11-07';

	/** Arribo a bodega que resulta de la fecha nueva. Es lo que le importa al cliente. */
	private const ARRIBO = '2026-12-14';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// La parte plana no usa esc_html()/esc_url(): borran apóstrofes (revisión 1.116.8).
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		// Formato humano en español, sin depender del locale del contenedor.
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $ts ): string {
				$meses = array(
					1  => 'enero',
					2  => 'febrero',
					3  => 'marzo',
					4  => 'abril',
					5  => 'mayo',
					6  => 'junio',
					7  => 'julio',
					8  => 'agosto',
					9  => 'septiembre',
					10 => 'octubre',
					11 => 'noviembre',
					12 => 'diciembre',
				);
				$d     = getdate( $ts );
				return sprintf( '%d de %s de %d', $d['mday'], $meses[ $d['mon'] ], $d['year'] );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Renderiza uno de los dos templates y devuelve su salida.
	 *
	 * @param string $variante `html` o `plain`.
	 * @param string $mode     `date_changed` o `date_announced`.
	 * @param string $arribo   Fecha de arribo `Y-m-d`, o '' si no hay ETA calculable.
	 * @param string $vieja    Lanzamiento previo `Y-m-d` ('' cuando se anuncia por primera vez).
	 */
	private function render( string $variante, string $mode, string $arribo, string $vieja = self::EDITORIAL_VIEJA, bool $sin_fecha_al_comprar = true, ?string $arribo_pedido = null ): string {
		// Se EXTIENDE el doble de WC_Order en vez de inventar uno anónimo:
		// `ContactFooter::render_plain()` exige el tipo real, y un doble suelto
		// dejaría verde una prueba sobre un template que en producción falla.
		$order = new PedidoConDatosDeCliente();
		$item  = new WC_Order_Item_Product();

		// `do_action( 'woocommerce_email_header', ..., $email )` es no-op bajo
		// Brain Monkey; sólo necesita que la variable exista.
		$email = new \stdClass();

		$vars = array(
			'order'              => $order,
			'mode'               => $mode,
			'preventa_items'     => array( $item ),
			'summary_max_ymd'    => $arribo_pedido ?? $arribo,
			'item_max_ymd'       => $arribo,
			'date_change_ctx'    => array(
				'product_id'   => 30410,
				'product_name' => 'Frieren 06',
				'old_ymd'      => $vieja,
				'new_ymd'      => self::EDITORIAL_NUEVA,
			),
			'email_heading'       => 'Cambio de fecha en tu preventa',
			'additional_content'  => '',
			'bought_without_date' => $sin_fecha_al_comprar,
		);

		$ruta = AKIBARA_BUSINESS_DIR . 'templates/emails/'
			. ( 'plain' === $variante ? 'plain/' : '' )
			. 'customer-preventa-reminder.php';

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- contrato del template.
		extract( $vars );
		unset( $vars );
		ob_start();
		require $ruta;
		return (string) ob_get_clean();
	}

	/** @return array<string, array{0:string, 1:string}> */
	public static function variantes(): array {
		return array(
			'HTML'         => array( 'html', 'date_changed' ),
			'texto plano'  => array( 'plain', 'date_changed' ),
		);
	}

	/**
	 * @dataProvider variantes
	 */
	public function test_el_aviso_de_postergacion_dice_cuando_le_llega_al_cliente( string $variante, string $mode ): void {
		$salida = $this->render( $variante, $mode, self::ARRIBO );

		$this->assertStringContainsString(
			'14 de diciembre de 2026',
			$salida,
			'El correo de postergación debe decir el ARRIBO estimado; sin él, el cliente sólo ve la fecha de la editorial y la lee como un adelanto.'
		);
	}

	/**
	 * @dataProvider variantesSimples
	 */
	public function test_el_aviso_de_postergacion_nombra_el_tomo_y_las_dos_salidas( string $variante ): void {
		// Como lo lee el cliente: sin etiquetas ni el sangrado del template.
		$salida = $this->texto( $this->render( $variante, 'date_changed', self::ARRIBO ) );

		$this->assertStringContainsString( 'la editorial movió la fecha de salida de Frieren 06', $salida, 'Antes decía «un cambio en la fecha estimada de tu pedido» y no nombraba el tomo.' );
		$this->assertStringContainsString( 'Salía el 17 de octubre de 2026; ahora sale el 7 de noviembre de 2026', $salida );
		$this->assertStringContainsString( 'Te llegaría alrededor del 14 de diciembre de 2026', $salida );
	}

	/**
	 * Con dos tomos, el arribo que se imprime es el del TOMO que cambió; el del
	 * pedido completo se dice aparte y sólo si es otro.
	 *
	 * @dataProvider variantesSimples
	 */
	public function test_el_arribo_es_el_del_tomo_y_el_pedido_completo_se_dice_aparte( string $variante ): void {
		$salida = $this->texto( $this->render( $variante, 'date_changed', self::ARRIBO, self::EDITORIAL_VIEJA, true, '2027-01-20' ) );

		$this->assertStringContainsString( 'Te llegaría alrededor del 14 de diciembre de 2026', $salida );
		$this->assertStringContainsString( 'Tu pedido completo sale cuando llegue todo: alrededor del 20 de enero de 2027', $salida, 'Antes imprimía el 20 de enero como si fuera la fecha de este tomo.' );

		$solo = $this->texto( $this->render( $variante, 'date_changed', self::ARRIBO ) );
		$this->assertStringNotContainsString( 'pedido completo', $solo, 'Con un solo tomo no hay nada que separar.' );
	}

	/** El correo tal como se lee: sin etiquetas y con los espacios colapsados. */
	private function texto( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $html ) ) ) );
	}

	/** @return array<string, array{0:string}> */
	public static function variantesSimples(): array {
		return array(
			'HTML'        => array( 'html' ),
			'texto plano' => array( 'plain' ),
		);
	}

	/**
	 * @dataProvider variantesSimples
	 */
	public function test_el_anuncio_de_primera_fecha_dice_cuando_le_llega_al_cliente( string $variante ): void {
		$salida = $this->render( $variante, 'date_announced', self::ARRIBO, '' );

		$this->assertStringContainsString(
			'14 de diciembre de 2026',
			$salida,
			'«Ya tenemos fecha» prometía la salida de la editorial y en el párrafo siguiente hablaba del arribo a bodega: la contradicción vivía dentro del mismo correo.'
		);
	}

	/**
	 * @dataProvider variantesSimples
	 */
	public function test_la_fecha_de_la_editorial_no_se_presenta_como_estimada( string $variante ): void {
		$salida = $this->render( $variante, 'date_changed', self::ARRIBO );

		$this->assertStringNotContainsString(
			'Fecha nueva estimada',
			$salida,
			'La fecha de la editorial es su fecha de salida, no una estimación de entrega.'
		);
	}

	/**
	 * Sin `pa_pais` o sin lead time no hay arribo calculable. El correo debe
	 * seguir saliendo con la fecha de la editorial y SIN una línea de arribo
	 * vacía o con un guion suelto.
	 *
	 * @dataProvider variantesSimples
	 */
	public function test_sin_arribo_calculable_no_promete_una_fecha_vacia( string $variante ): void {
		$salida = $this->render( $variante, 'date_changed', '' );

		$this->assertStringContainsString( '7 de noviembre de 2026', $salida );

		// Se cuentan las fechas en vez de buscar una etiqueta: un renombrado no
		// debe dejar verde la desaparición (ni la aparición espuria) de una fecha.
		$this->assertSame(
			2,
			$this->cuantas_fechas( $salida ),
			'Sin arribo calculable sólo caben las dos de la editorial (previa y nueva).'
		);
		$this->assertSame(
			3,
			$this->cuantas_fechas( $this->render( $variante, 'date_changed', self::ARRIBO ) ),
			'Con arribo calculable el cliente debe ver tres: las dos de la editorial y la de llegada.'
		);
	}

	/**
	 * Cuenta las fechas humanas («14 de diciembre de 2026») de una salida.
	 */
	private function cuantas_fechas( string $salida ): int {
		return preg_match_all( '/\\d{1,2} de [a-zé]+ de \\d{4}/u', $salida );
	}

	/**
	 * «Que compraste sin fecha confirmada» sólo es verdad para una preventa
	 * `tba`. Un tomo a pedido ya tenía un rango prometido: para ese cliente la
	 * fecha anunciada es un cambio, y se le da la misma salida que en una
	 * postergación.
	 *
	 * @dataProvider variantesSimples
	 */
	public function test_el_anuncio_a_un_cliente_a_pedido_no_le_dice_que_compro_sin_fecha( string $variante ): void {
		$salida = $this->render( $variante, 'date_announced', self::ARRIBO, '', false );

		$this->assertStringNotContainsString( 'sin fecha confirmada', $salida );
		$this->assertStringContainsString( 'la editorial anunció la fecha de salida de', $salida );
		$this->assertStringContainsString( 'Frieren 06', $salida, 'En un pedido de varias líneas hay que decir CUÁL tomo cambió.' );
		$this->assertStringContainsString( 'cancelar y recibir reembolso', $salida, 'La fecha anunciada puede ser peor que la prometida: hay que darle la salida.' );
		$this->assertStringContainsString( '14 de diciembre de 2026', $salida );
	}

	/**
	 * @dataProvider variantesSimples
	 */
	public function test_el_anuncio_a_una_preventa_sin_fecha_sigue_siendo_buena_noticia( string $variante ): void {
		$salida = $this->render( $variante, 'date_announced', self::ARRIBO, '', true );

		$this->assertStringContainsString( 'sin fecha confirmada', $salida );
		$this->assertStringNotContainsString( 'cancelar y recibir reembolso', $salida );
	}

	/**
	 * @testdox Si el arribo estimado quedó ANTES de la nueva salida de la editorial, el correo no lo imprime (antes que contradecirse)
	 * @dataProvider variantes
	 */
	public function test_un_arribo_anterior_a_la_salida_no_se_imprime( string $variante ): void {
		$html = $this->render( $variante, 'date_changed', '2026-10-20' );
		self::assertStringContainsString( 'ahora sale el', $html );
		self::assertStringNotContainsString( 'Te llegaría', $html, 'Un arribo cuatro semanas antes de la salida que el mismo correo anuncia es una contradicción.' );

		$coherente = $this->render( $variante, 'date_changed', self::ARRIBO );
		self::assertStringContainsString( 'Te llegaría', $coherente );
	}
}
