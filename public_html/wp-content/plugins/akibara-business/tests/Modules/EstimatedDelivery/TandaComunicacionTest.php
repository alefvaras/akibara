<?php
/**
 * Lo que el cliente entiende sobre CUÁNDO empieza a correr el plazo.
 *
 * La fecha del banner ya era correcta antes de esta ronda: `DateCalculator`
 * cuenta desde el CIERRE de la tanda, no desde la compra. Lo que fallaba era la
 * comunicación, y falla siempre igual —el cliente lee el rango, no encuentra
 * ninguna frase que le diga desde cuándo se cuenta, y completa el hueco con lo
 * único que conoce: el día que compró—. De ahí los tres contratos de este
 * archivo:
 *
 *   1) La frase de tanda dice el corte Y las semanas que viaja desde ahí, y esas
 *      semanas se DERIVAN de la misma política que produjo las fechas. Un
 *      literal escrito a mano se desincroniza el día que el dueño cambia el
 *      plazo, y las dos mitades del banner se seguirían leyendo correctas por
 *      separado — el defecto más caro de encontrar.
 *   2) La explicación viaja al carrito. Con varias tandas manda el corte MÁS
 *      TARDÍO, misma regla conservadora que `aggregate()`: el pedido viaja
 *      completo, y quien lo retiene es el corte de más allá.
 *   3) Cuando el corte está a ≤7 días la ficha dice cuántos quedan, contados en
 *      días CORRIDOS y en la zona de la tienda. El día del cierre todavía se
 *      entra (regla de `proximo_cierre`), así que ese día el aviso es «hoy es el
 *      último», no «quedan 0».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TandaComunicacionTest extends TestCase {

	private const TZ = 'America/Santiago';

	/** Términos de la fixture, con los cortes REALES de producción. */
	private const TERM_ESPANA    = 154;
	private const TERM_ARGENTINA = 98;

	/** España: cierra el 5, viaja 25–35 días corridos → 4–5 semanas. */
	private const ESPANA = array( 'dia' => 5, 'min' => 25, 'max' => 35 );

	/** Argentina: cierra el 20, viaja 18–24 días corridos → ~3 semanas. */
	private const ARGENTINA = array( 'dia' => 20, 'min' => 18, 'max' => 24 );

	private \DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tz = new \DateTimeZone( self::TZ );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias(
			fn(): \DateTimeZone => new \DateTimeZone( self::TZ )
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$args ) => $value
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function ts( string $fecha ): int {
		return ( new \DateTimeImmutable( $fecha . ' 12:00:00', $this->tz ) )->getTimestamp();
	}

	/**
	 * @param array{dia:int,min:int,max:int} $corte
	 * @return array{modo:string,dia:int,min:int,max:int,marca:string}
	 */
	private function politica( array $corte, string $marca = 'Ivrea' ): array {
		return array(
			'modo'  => PedidoPolicy::MODO_TANDA,
			'dia'   => $corte['dia'],
			'min'   => $corte['min'],
			'max'   => $corte['max'],
			'marca' => $marca,
		);
	}

	// -----------------------------------------------------------------------
	// 1. Las semanas SALEN de la política.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Las semanas del banner se derivan de los días de la política, nunca de un literal.
	 *
	 * Es el contrato que hace sostenible el resto: si mañana el dueño alarga el
	 * plazo de España en el panel de cortes, la frase tiene que decir el plazo
	 * nuevo sin que nadie toque una línea de copy.
	 */
	public function test_the_weeks_come_from_the_policy_days(): void {
		$p = new PedidoPolicy();

		self::assertSame(
			'Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 4–5 semanas.',
			$p->frase_de_tanda( $this->politica( self::ESPANA ), $this->ts( '2026-08-24' ), $this->tz )
		);

		// La MISMA fecha de corte con otro plazo dice otras semanas: la segunda
		// mitad de la frase depende de la política, no del calendario.
		self::assertSame(
			'Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja ~6 semanas.',
			$p->frase_de_tanda(
				$this->politica( array( 'dia' => 5, 'min' => 40, 'max' => 44 ) ),
				$this->ts( '2026-08-24' ),
				$this->tz
			)
		);

		self::assertSame(
			'Sale en el pedido que cierra el 20 de septiembre; desde ahí viaja ~3 semanas.',
			$p->frase_de_tanda( $this->politica( self::ARGENTINA ), $this->ts( '2026-08-24' ), $this->tz )
		);
	}

	/**
	 * @return array<string, array{0:int, 1:int, 2:string}>
	 */
	public static function plazos(): array {
		return array(
			'España 25–35 son 4–5 semanas'          => array( 25, 35, '4–5 semanas' ),
			'Argentina 18–24 redondean a la misma'  => array( 18, 24, '~3 semanas' ),
			'un plazo exacto de una semana'         => array( 7, 7, '~1 semana' ),
			'el clamp: 3 días no son cero semanas'  => array( 3, 3, '~1 semana' ),
			'el clamp no aplasta el techo'          => array( 2, 20, '1–3 semanas' ),
			'un máximo invertido no invierte nada'  => array( 30, 10, '~4 semanas' ),
			'plazo cero es el mínimo honesto'       => array( 0, 0, '~1 semana' ),
			'un plazo largo sigue siendo semanas'   => array( 60, 90, '9–13 semanas' ),
		);
	}

	/**
	 * @dataProvider plazos
	 * @testdox Los días corridos se traducen a semanas, con clamp inferior en 1.
	 *
	 * El clamp no es cosmético. `round(3/7)` es 0, y «desde ahí viaja 0 semanas»
	 * convertiría una promesa conservadora en una imposible: el cliente leería
	 * que el pedido llega el mismo día que cierra.
	 */
	public function test_days_become_weeks_with_a_floor_of_one( int $min, int $max, string $esperado ): void {
		self::assertSame( $esperado, PedidoPolicy::semanas_de_tanda( $min, $max ) );
	}

	/**
	 * @testdox La frase sigue callándose cuando la editorial no va por tanda.
	 *
	 * El «desde ahí viaja» es la mitad NUEVA, pero la condición de aparición es
	 * la de siempre: la misma que usa el calculador para aplicar el corte.
	 */
	public function test_the_sentence_still_stays_silent_outside_batch_mode(): void {
		$p = new PedidoPolicy();

		foreach ( array( PedidoPolicy::MODO_HEREDADO, PedidoPolicy::MODO_CONTINUO, PedidoPolicy::MODO_PAUSADA ) as $modo ) {
			$politica         = $this->politica( self::ESPANA );
			$politica['modo'] = $modo;

			self::assertSame( '', $p->frase_de_tanda( $politica, $this->ts( '2026-08-24' ), $this->tz ) );
			self::assertSame( '', $p->frase_de_urgencia( $politica, $this->ts( '2026-09-01' ), $this->tz, $this->ts( '2026-09-01' ) ) );
		}
	}

	// -----------------------------------------------------------------------
	// 3. Urgencia: cuántos días quedan para entrar.
	// -----------------------------------------------------------------------

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function cuentas_atras(): array {
		return array(
			'ocho días es demasiado pronto'   => array( '2026-08-28', '' ),
			'siete días abre la ventana'      => array( '2026-08-29', 'Quedan 7 días para entrar en el pedido del 5 de septiembre' ),
			'cinco días'                      => array( '2026-08-31', 'Quedan 5 días para entrar en el pedido del 5 de septiembre' ),
			'un día va en singular'           => array( '2026-09-04', 'Queda 1 día para entrar en el pedido del 5 de septiembre' ),
			'el día del cierre todavía entra' => array( '2026-09-05', 'Hoy es el último día para entrar en el pedido del 5 de septiembre' ),
			'pasado el corte no dice nada'    => array( '2026-09-06', '' ),
			'y sigue callado a media espera'  => array( '2026-09-20', '' ),
		);
	}

	/**
	 * @dataProvider cuentas_atras
	 * @testdox La cuenta atrás sólo habla dentro de la ventana de 7 días.
	 *
	 * Los dos extremos son los que importan. El día del cierre NO es «quedan 0»:
	 * comprar el 5 entra en la tanda del 5 (regla fijada en `PedidoPolicyTest`),
	 * así que ese día el aviso tiene que empujar, no cerrar la puerta. Y el 6 no
	 * dice nada raro: `proximo_cierre()` ya apunta al corte de octubre, que está
	 * a 29 días, y el aviso simplemente se apaga hasta que ese se acerque.
	 */
	public function test_the_countdown_only_speaks_inside_its_window( string $hoy, string $esperado ): void {
		$p = new PedidoPolicy();

		self::assertSame(
			$esperado,
			$p->frase_de_urgencia( $this->politica( self::ESPANA ), $this->ts( $hoy ), $this->tz, $this->ts( $hoy ) )
		);
	}

	/**
	 * @testdox La versión compacta de la grilla dice lo mismo sin repetir la fecha.
	 *
	 * En /a-pedido/ todas las tarjetas comparten origen: con la frase larga serían
	 * dos docenas de copias de «…en el pedido del 5 de septiembre» en una pantalla.
	 * La sale del MISMO método, así que las dos versiones no pueden divergir en el
	 * número de días ni en la ventana.
	 */
	public function test_the_compact_countdown_drops_the_date_but_not_the_days(): void {
		$p        = new PedidoPolicy();
		$politica = $this->politica( self::ESPANA );

		self::assertSame(
			'Quedan 5 días para entrar',
			$p->frase_de_urgencia( $politica, $this->ts( '2026-08-31' ), $this->tz, $this->ts( '2026-08-31' ), true )
		);
		self::assertSame(
			'Queda 1 día para entrar',
			$p->frase_de_urgencia( $politica, $this->ts( '2026-09-04' ), $this->tz, $this->ts( '2026-09-04' ), true )
		);
		self::assertSame(
			'Hoy es el último día para entrar',
			$p->frase_de_urgencia( $politica, $this->ts( '2026-09-05' ), $this->tz, $this->ts( '2026-09-05' ), true )
		);
		self::assertSame(
			'',
			$p->frase_de_urgencia( $politica, $this->ts( '2026-08-28' ), $this->tz, $this->ts( '2026-08-28' ), true )
		);
	}

	/**
	 * @testdox Los días son CORRIDOS y de calendario, no múltiplos de 86.400 segundos.
	 *
	 * Chile adelanta el reloj el primer domingo de septiembre. Con aritmética de
	 * segundos, la mañana del cambio de hora «quedan 2 días» se convierte en
	 * «queda 1» — y el resto del módulo ya usa `DateTimeImmutable` justamente por
	 * esto.
	 */
	public function test_the_countdown_counts_calendar_days_across_a_dst_jump(): void {
		// 2026-09-06 es el primer domingo de septiembre: el día dura 23 horas.
		self::assertSame(
			2,
			PedidoPolicy::dias_corridos_hasta( $this->ts( '2026-09-05' ), $this->ts( '2026-09-07' ), $this->tz )
		);
		self::assertSame(
			-2,
			PedidoPolicy::dias_corridos_hasta( $this->ts( '2026-09-07' ), $this->ts( '2026-09-05' ), $this->tz )
		);
		self::assertSame(
			0,
			PedidoPolicy::dias_corridos_hasta( $this->ts( '2026-09-05' ), $this->ts( '2026-09-05' ), $this->tz )
		);
	}

	// -----------------------------------------------------------------------
	// Superficies: ficha y grilla.
	// -----------------------------------------------------------------------

	/**
	 * @testdox La ficha imprime la nota y, dentro de la ventana, la cuenta atrás.
	 */
	public function test_the_product_page_prints_the_note_and_the_countdown(): void {
		$this->stub_catalogo( array( 4242 => 'espana' ) );

		$html = $this->render_single( $this->renderer( '2026-08-31' ), $this->producto( 4242 ) );

		self::assertStringContainsString(
			'<span class="akb-eta__nota">Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 4–5 semanas.</span>',
			$html
		);
		self::assertStringContainsString(
			'<span class="akb-eta__urgencia">Quedan 5 días para entrar en el pedido del 5 de septiembre</span>',
			$html
		);
		// El orden importa: primero la mecánica, después la presión. Al revés es
		// una cuenta atrás sin explicación.
		self::assertLessThan(
			(int) strpos( $html, 'akb-eta__urgencia' ),
			(int) strpos( $html, 'akb-eta__nota' ),
			'La urgencia sólo significa algo después de explicar qué es el corte.'
		);
	}

	/**
	 * @testdox Fuera de la ventana la ficha mantiene la nota y calla la cuenta atrás.
	 */
	public function test_outside_the_window_the_page_keeps_the_note_and_drops_the_countdown(): void {
		$this->stub_catalogo( array( 4242 => 'espana' ) );

		$html = $this->render_single( $this->renderer( '2026-08-10' ), $this->producto( 4242 ) );

		self::assertStringContainsString( 'akb-eta__nota', $html );
		self::assertStringNotContainsString( 'akb-eta__urgencia', $html );
	}

	/**
	 * @testdox La píldora de la grilla lleva la cuenta atrás compacta, y sólo eso.
	 *
	 * La nota entera NO baja a la tarjeta: repetida en 24 cards sería ruido, y esa
	 * decisión ya estaba tomada. Lo que sí baja es la urgencia, que es lo único
	 * que caduca.
	 */
	public function test_the_grid_pill_carries_only_the_compact_countdown(): void {
		$this->stub_catalogo( array( 4242 => 'espana' ) );

		$html = $this->render_pill( $this->renderer( '2026-08-31' ), $this->producto( 4242 ) );

		self::assertStringContainsString(
			'<span class="akb-eta-loop__urgencia">Quedan 5 días para entrar</span>',
			$html
		);
		self::assertStringNotContainsString( 'Sale en el pedido', $html );

		// Y fuera de la ventana la tarjeta vuelve a su markup de siempre.
		$lejos = $this->render_pill( $this->renderer( '2026-08-10' ), $this->producto( 4242 ) );
		self::assertStringNotContainsString( 'akb-eta-loop__urgencia', $lejos );
	}

	// -----------------------------------------------------------------------
	// 2. La nota viaja al carrito.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El carrito mixto explica el corte MÁS TARDÍO, con las semanas de ESE corte.
	 *
	 * España cierra el 5 de septiembre y Argentina el 20: el pedido no puede salir
	 * antes del 20, así que nombrar el de España prometería una caja que todavía
	 * no existe. Y las semanas tienen que ser las de Argentina (~3), no las de
	 * España (4–5): quien decide cuándo parte el pedido es quien describe cuánto
	 * viaja.
	 */
	public function test_a_mixed_cart_explains_the_latest_cutoff(): void {
		$this->stub_catalogo(
			array(
				100 => 'espana',
				200 => 'argentina',
			)
		);

		$resumen = $this->renderer( '2026-08-24' )->summarize_cart(
			array(
				array( 'data' => $this->producto( 100 ) ),
				array( 'data' => $this->producto( 200 ) ),
				// Un tomo en stock no aporta corte ni fecha: se ignora entero.
				array( 'data' => $this->producto( 300, StockStatus::IN_STOCK ) ),
			)
		);

		self::assertIsArray( $resumen );
		self::assertSame(
			'Sale en el pedido que cierra el 20 de septiembre; desde ahí viaja ~3 semanas.',
			$resumen['nota']
		);
		self::assertFalse( $resumen['is_tba'] );
	}

	/**
	 * @testdox Un carrito sin tandas no inventa nota.
	 */
	public function test_a_cart_without_batches_has_no_note(): void {
		$this->stub_catalogo( array( 100 => 'espana' ), false );

		$resumen = $this->renderer( '2026-08-24' )->summarize_cart(
			array( array( 'data' => $this->producto( 100 ) ) )
		);

		self::assertIsArray( $resumen );
		self::assertSame( '', $resumen['nota'] );
	}

	/**
	 * @testdox Un carrito sin nada reservable sigue devolviendo null.
	 */
	public function test_a_cart_without_reservable_items_stays_null(): void {
		$this->stub_catalogo( array( 300 => 'espana' ) );

		self::assertNull(
			$this->renderer( '2026-08-24' )->summarize_cart(
				array( array( 'data' => $this->producto( 300, StockStatus::IN_STOCK ) ) )
			)
		);
	}

	// -----------------------------------------------------------------------
	// Andamiaje.
	// -----------------------------------------------------------------------

	private function producto( int $id, string $stock = StockStatus::ON_BACKORDER ): \WC_Product {
		$producto = new class( $stock ) extends \WC_Product {
			public int $akb_forced_id = 0;

			public function get_id(): int {
				return $this->akb_forced_id;
			}
		};

		$producto->akb_forced_id = $id;

		return $producto;
	}

	/**
	 * Stubs de la capa de datos: qué país tiene cada producto y qué corte tiene
	 * cada país. Ninguna editorial lleva política propia en esta fixture, así que
	 * manda el fallback por país — que es la configuración REAL de producción.
	 *
	 * @param array<int, string> $paises     product_id → slug del término pa_pais.
	 * @param bool               $con_cortes Si false, los países no llevan tanda.
	 */
	private function stub_catalogo( array $paises, bool $con_cortes = true ): void {
		$terminos = array(
			'espana'    => self::TERM_ESPANA,
			'argentina' => self::TERM_ARGENTINA,
		);

		Functions\when( 'get_post_meta' )->justReturn( '' );

		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) use ( $paises, $terminos ) {
				// La editorial EXISTE pero hereda (sin metas de política), que es la
				// forma real de los 422 productos a pedido de producción: la regla la
				// pone el país. Darle término aunque no configure nada importa —
				// probar sobre un producto sin editorial ejercitaría otro camino.
				if ( 'product_brand' === $taxonomy && isset( $paises[ $post_id ] ) ) {
					return array( (object) array( 'term_id' => 500, 'name' => 'Ivrea' ) );
				}

				if ( 'pa_pais' !== $taxonomy || ! isset( $paises[ $post_id ] ) ) {
					return false;
				}

				$slug = $paises[ $post_id ];

				return array(
					(object) array(
						'term_id' => $terminos[ $slug ],
						'slug'    => $slug,
						'name'    => 'espana' === $slug ? 'España' : 'Argentina',
					),
				);
			}
		);

		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = true ) use ( $con_cortes ) {
				unset( $single );

				if ( ! $con_cortes ) {
					return '';
				}

				$cortes = array(
					self::TERM_ESPANA    => self::ESPANA,
					self::TERM_ARGENTINA => self::ARGENTINA,
				);

				if ( ! isset( $cortes[ $term_id ] ) ) {
					return '';
				}

				switch ( $key ) {
					case PedidoPolicy::META_MODO:
						return PedidoPolicy::MODO_TANDA;
					case PedidoPolicy::META_DIA:
						return $cortes[ $term_id ]['dia'];
					case PedidoPolicy::META_MIN:
						return $cortes[ $term_id ]['min'];
					case PedidoPolicy::META_MAX:
						return $cortes[ $term_id ]['max'];
					default:
						return '';
				}
			}
		);

		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, $value, string $taxonomy ) use ( $terminos ) {
				unset( $field );

				if ( 'pa_pais' !== $taxonomy || ! isset( $terminos[ $value ] ) ) {
					return false;
				}

				return (object) array(
					'term_id' => $terminos[ $value ],
					'name'    => 'espana' === $value ? 'España' : 'Argentina',
				);
			}
		);
	}

	/**
	 * Renderer con el reloj congelado. Congelarlo en el CALCULADOR y no en el
	 * renderer es el punto: la cuenta atrás se mide contra el mismo reloj que
	 * produjo la fecha, así que una prueba no puede fijar una mitad y dejar la
	 * otra leyendo la hora real.
	 */
	private function renderer( string $hoy ): BannerRenderer {
		$tz = new \DateTimeZone( self::TZ );

		return new BannerRenderer(
			new DateCalculator(
				new Settings(),
				static fn(): int => ( new \DateTimeImmutable( $hoy . ' 10:00:00', $tz ) )->getTimestamp(),
				$tz
			)
		);
	}

	private function render_single( BannerRenderer $renderer, \WC_Product $product ): string {
		$GLOBALS['product'] = $product;

		ob_start();
		$renderer->render_single();

		return (string) ob_get_clean();
	}

	private function render_pill( BannerRenderer $renderer, \WC_Product $product ): string {
		$GLOBALS['product'] = $product;

		ob_start();
		$renderer->render_loop_item();

		return (string) ob_get_clean();
	}
}
