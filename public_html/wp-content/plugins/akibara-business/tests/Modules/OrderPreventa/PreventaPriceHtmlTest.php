<?php
/**
 * Tests del precio de una card en PREVENTA (bug medido en prod el 2026-08-09).
 *
 * Los 5 tomos en preventa de `/serie/the-climber/` mostraban «$14.000» tachado y
 * «$13.500» —la lectura de REBAJA, que acá es falsa: $14.000 es el precio FUTURO,
 * no uno anterior— mientras el único argumento para pagar hoy algo que llega el
 * 1–4 de septiembre vivía completo dentro de un `screen-reader-text`. Con los dos
 * importes visibles marcados `aria-hidden` por WooCommerce core, las capas estaban
 * exactamente invertidas.
 *
 * Estos tests fijan las dos mitades del contrato, que se sostienen juntas o el
 * arreglo se vuelve regresión:
 *   (1) la dirección del precio es VISIBLE, y
 *   (2) la capa accesible sigue nombrando el IMPORTE VIGENTE — y no repite el
 *       futuro, que ahora lo carga la línea visible.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\PreventaUX;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class PreventaPriceHtmlTest extends TestCase {

	/**
	 * Lo que `wc_format_sale_price()` entrega a este filtro para el tomo 15 de The
	 * Climber, copiado del HTML capturado en producción el 2026-08-09 (mismos
	 * atributos, misma entidad `&#36;` del símbolo).
	 *
	 * Literal a propósito: si WooCommerce cambia la forma del precio de oferta, el
	 * `preg_replace` que barre los `screen-reader-text` de core se entera acá y no
	 * en las 472 cards de preventa.
	 */
	private const WC_SALE_HTML = '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
		. '<span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>14.000</bdi></span></del>'
		. ' <span class="screen-reader-text">El precio original era: &#036;14.000.</span>'
		. '<ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
		. '<span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>13.500</bdi></span></ins>'
		. '<span class="screen-reader-text">El precio actual es: &#036;13.500.</span>';

	/**
	 * Overrides de filtro por test, leídos por el doble de `apply_filters`.
	 *
	 * Vive como propiedad y no como `Functions\when()` dentro del test porque
	 * mockear una función la define para TODO el proceso: declarar el doble sólo
	 * en `setUp()` deja la suite verde en cualquier permutación de `--order-by=random`.
	 *
	 * @var array<string, mixed>
	 */
	private array $filter_overrides = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->filter_overrides = array();

		Functions\when( '__' )->returnArg( 1 );

		/*
		 * Estado comercial por defecto de la suite: reservable SIN metas de
		 * lanzamiento — o sea «A pedido», que es la realidad de todo el catálogo
		 * al separarse los estados (1.109.0). La palabra del importe pasa a ser
		 * «reserva»; el caso «preventa» (lanzamiento pendiente) se fija aparte en
		 * test_preventa_word_appears_only_with_a_pending_launch.
		 */
		Functions\when( 'get_post_meta' )->justReturn( '' );

		/*
		 * Superficie por defecto: una página del front que no es el carrito, o sea
		 * el archive y la ficha donde la nota SÍ se publica. Se declaran los cuatro
		 * guards aunque el test concreto no los use, por la misma disciplina que
		 * documenta `LandingOfferTest`: mockear una función la define para todo el
		 * proceso, y un test que llegue con el símbolo definido y sin expectativa
		 * recibiría `null` — verde en orden natural, rojo con `--order-by=random`.
		 */
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );

		/*
		 * `esc_html` NO se dobla como identidad: el string que este módulo escapa
		 * contiene la entidad `&#36;` del símbolo de peso que emite `wc_price()`, y
		 * el modo de falla que importa es justamente el doble escapado
		 * (`&amp;#36;` → el usuario lee «&#36;13.500» en pantalla). El doble espeja
		 * `_wp_specialchars( $text, ENT_QUOTES, false, $double_encode = false )`:
		 * normaliza la entidad numérica a 3 dígitos como `wp_kses_normalize_entities()`
		 * y no re-codifica lo ya formado. Con eso, la salida esperada de estos tests
		 * es byte a byte la que sirve producción.
		 */
		Functions\when( 'esc_html' )->alias(
			static function ( $text ): string {
				$normalizado = (string) preg_replace_callback(
					'/&#0*([0-9]{1,7});/',
					static fn( array $m ): string => '&#' . str_pad( $m[1], 3, '0', STR_PAD_LEFT ) . ';',
					(string) $text
				);

				return htmlspecialchars( $normalizado, ENT_QUOTES, 'UTF-8', false );
			}
		);

		// Suficientemente fiel: lo que el módulo le pide a `wp_strip_all_tags()` es
		// quedarse con el texto del importe, sin marcado que herede estilo de precio.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $text ): string => strip_tags( (string) $text )
		);

		// Forma real de `wc_price()` en es_CL, con la entidad del símbolo.
		Functions\when( 'wc_price' )->alias(
			static fn( $amount ): string => '<span class="woocommerce-Price-amount amount"><bdi>'
				. '<span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>'
				. number_format( (float) $amount, 0, ',', '.' ) . '</bdi></span>'
		);

		$overrides = &$this->filter_overrides;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, ...$args ) use ( &$overrides ) {
				unset( $args );
				return array_key_exists( $hook, $overrides ) ? $overrides[ $hook ] : $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Producto en preventa con incentivo de reserva: el precio de preventa vigente
	 * ($13.500) y el regular escrito por el dueño ($14.000), que es el que se
	 * cobrará al llegar el stock. Es la forma real de los 5 tomos medidos.
	 *
	 * @param string $stock_status Estado de stock crudo.
	 * @param string $regular      Precio regular ('' = no escrito).
	 * @param string $sale         Precio de preventa.
	 * @param bool   $on_sale      ¿WooCommerce lo considera en oferta?
	 * @param string $type         Tipo de producto WC.
	 */
	private function product(
		string $stock_status = StockStatus::ON_BACKORDER,
		string $regular = '14000',
		string $sale = '13500',
		bool $on_sale = true,
		string $type = 'simple'
	): WC_Product {
		$product                    = new WC_Product( $stock_status );
		$product->akb_regular_price = $regular;
		$product->akb_sale_price    = $sale;
		$product->akb_on_sale       = $on_sale;
		$product->akb_type          = $type;

		return $product;
	}

	/**
	 * @param string $html HTML de entrada (por defecto el de producción).
	 */
	private function filtered( ?WC_Product $product = null, string $html = self::WC_SALE_HTML ): string {
		return ( new PreventaUX() )->rewrite_preventa_price_html(
			$html,
			$product ?? $this->product()
		);
	}

	/**
	 * Devuelve el contenido de la nota visible, o '' si no se emitió.
	 */
	private function nota( string $html ): string {
		return preg_match( '#<small class="akb-preventa-alza">(.*?)</small>#s', $html, $m ) ? $m[1] : '';
	}

	/**
	 * Devuelve el contenido del `screen-reader-text` propio, o '' si no hay.
	 */
	private function lectura( string $html ): string {
		return preg_match( '#<span class="screen-reader-text">(.*?)</span>#s', $html, $m ) ? $m[1] : '';
	}

	/**
	 * El arreglo: la dirección del precio deja de ser información sólo audible.
	 *
	 * @testdox Should print a visible micro line naming the price direction.
	 */
	public function test_preventa_prints_a_visible_price_direction(): void {
		$html = $this->filtered();

		self::assertStringContainsString( '<small class="akb-preventa-alza">', $html );
		self::assertSame( 'Al llegar el stock sube a &#036;14.000', $this->nota( $html ) );
	}

	/**
	 * `woocommerce_get_price_html` es GLOBAL: sale en toda superficie que imprima
	 * un precio, no sólo en el loop y la PDP que se analizaron. La que muerde es el
	 * índice de `akibara-search`, que MATERIALIZA `wp_kses_post( get_price_html() )`
	 * en una columna y lo inyecta crudo en una fila de sugerencia de 40 px de alto
	 * junto al badge. El índice se construye por Action Scheduler / WP-CLI /
	 * `save_post`, donde `template_redirect` nunca se disparó.
	 *
	 * @testdox Should keep the note out of the search index build.
	 */
	public function test_note_is_not_baked_when_no_page_is_being_rendered(): void {
		Functions\when( 'did_action' )->justReturn( 0 );

		$html = $this->filtered();

		self::assertSame( '', $this->nota( $html ), 'La nota no puede viajar al índice.' );
		self::assertStringContainsString(
			'akb-price--preventa',
			$html,
			'El des-escalado visual y la clase SÍ deben seguir viajando: son del precio, no de la nota.'
		);
		self::assertStringContainsString( '13.500', $this->lectura( $html ) );
	}

	/**
	 * Mismo caso por el otro camino: Action Scheduler corre por un loopback a
	 * `admin-ajax.php`, donde `is_admin()` es true.
	 *
	 * @testdox Should keep the note out of admin-side renders.
	 */
	public function test_note_is_not_baked_in_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		self::assertSame( '', $this->nota( $this->filtered() ) );
	}

	/**
	 * Carrito y checkout son del adaptador first-party de Blocks por contrato del
	 * módulo: dos voces sobre el mismo precio es justo lo que ese reparto evita.
	 *
	 * @testdox Should leave cart and checkout price presentation to the blocks adapter.
	 * @dataProvider provide_cart_surfaces
	 *
	 * @param string $surface Conditional tag que devuelve true.
	 */
	public function test_note_stays_out_of_cart_and_checkout( string $surface ): void {
		Functions\when( $surface )->justReturn( true );

		self::assertSame( '', $this->nota( $this->filtered() ) );
	}

	/** @return array<string, array<int, string>> */
	public static function provide_cart_surfaces(): array {
		return array(
			'carrito'  => array( 'is_cart' ),
			'checkout' => array( 'is_checkout' ),
		);
	}

	/**
	 * La otra mitad, y la que convierte un arreglo en regresión si se olvida: hoy
	 * el usuario de lector de pantalla al menos oye el precio vigente. Mover el
	 * texto a visible sin conservar este string lo dejaría SIN precio actual.
	 *
	 * @testdox Should keep an accessible string that still names the current amount.
	 */
	public function test_accessible_string_still_names_the_current_amount(): void {
		$lectura = $this->lectura( $this->filtered() );

		self::assertStringContainsString( 'Precio de reserva', $lectura );
		self::assertStringContainsString( '13.500', $lectura, 'El importe VIGENTE no puede desaparecer de la capa accesible.' );
	}

	/**
	 * La palabra «preventa» quedó reservada para el lanzamiento pendiente
	 * (separación 1.109.0): un backlist a pedido dice «reserva», y solo un tomo
	 * que aún no sale dice «preventa». Si este test falla en su primera mitad,
	 * alguien volvió a llamar preventa a todo lo reservable.
	 *
	 * @testdox Should say "preventa" only when a launch is actually pending.
	 */
	public function test_preventa_word_appears_only_with_a_pending_launch(): void {
		self::assertStringContainsString( 'Precio de reserva', $this->lectura( $this->filtered() ) );

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) {
				unset( $post_id, $single );
				return \Akibara\Business\Modules\Stock\Availability::META_TYPE === $key
					? \Akibara\Business\Modules\Stock\Availability::TYPE_TBA
					: '';
			}
		);

		self::assertStringContainsString( 'Precio de preventa', $this->lectura( $this->filtered() ) );
	}

	/**
	 * La nota visible también se anuncia (es texto, no `aria-hidden`), así que
	 * dejar la frase entera en el `screen-reader-text` haría oír el precio futuro
	 * DOS veces. La segunda frase se MUEVE, no se duplica.
	 *
	 * @testdox Should not announce the future amount twice.
	 */
	public function test_future_amount_is_not_announced_twice(): void {
		$html = $this->filtered();

		self::assertStringNotContainsString( '14.000', $this->lectura( $html ) );
		self::assertStringContainsString( '14.000', $this->nota( $html ) );
		self::assertSame( 1, substr_count( $html, 'screen-reader-text' ), 'Un solo string SR: los dos de WC core se barren.' );
	}

	/**
	 * Orden en el DOM = orden de lectura. Primero cuánto cuesta hoy, después qué
	 * pasa cuando llegue: al revés, el primer número que oye el usuario es el que
	 * NO va a pagar.
	 *
	 * @testdox Should read the current amount before the price direction.
	 */
	public function test_current_amount_is_read_before_the_direction(): void {
		$html = $this->filtered();

		self::assertLessThan(
			(int) strpos( $html, '<small class="akb-preventa-alza">' ),
			(int) strpos( $html, '<span class="screen-reader-text">' )
		);
	}

	/**
	 * El defecto de origen era precisamente ocultar el argumento a la vista. La
	 * nota no puede nacer con el vicio contrario: ni `aria-hidden` ni dentro de un
	 * contenedor sólo-para-lectores.
	 *
	 * @testdox Should expose the visible note to both layers at once.
	 */
	public function test_visible_note_is_hidden_from_nobody(): void {
		$html = $this->filtered();

		self::assertDoesNotMatchRegularExpression( '#<small class="akb-preventa-alza"[^>]*aria-hidden#', $html );
		self::assertStringNotContainsString( '<span class="screen-reader-text"><small', $html );
	}

	/**
	 * Es una afirmación de precio ante el consumidor (SERNAC), no microcopy: sin
	 * un precio regular DISTINTO y MAYOR no hay nada que afirmar, y el filtro
	 * devuelve el html tal cual —sin nota, sin clase y sin tocar la lectura de WC—.
	 *
	 * @testdox Should print nothing extra when no regular price backs the claim.
	 *
	 * @dataProvider unsupported_claims
	 *
	 * @param string $regular Precio regular.
	 * @param string $sale    Precio de preventa.
	 * @param string $why     Motivo del contrato (mensaje de fallo).
	 */
	public function test_no_line_without_data_to_support_it( string $regular, string $sale, string $why ): void {
		$html = $this->filtered( $this->product( StockStatus::ON_BACKORDER, $regular, $sale ) );

		self::assertSame( self::WC_SALE_HTML, $html, $why );
		self::assertStringNotContainsString( 'akb-preventa-alza', $html );
		self::assertStringNotContainsString( 'Al llegar el stock', $html );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function unsupported_claims(): array {
		return array(
			'sin precio regular escrito'   => array( '', '13500', 'Sin regular no hay precio futuro que nombrar.' ),
			'regular igual al de preventa' => array( '13500', '13500', 'Si no sube, decir que sube sería falso.' ),
			'regular MENOR al de preventa' => array( '13000', '13500', 'La dirección sería la contraria: no se inventa.' ),
			'sin precio de preventa'       => array( '14000', '', 'Sin importe vigente no hay nada que comparar.' ),
		);
	}

	/**
	 * Con descuento sobre el umbral anti-trivial ESO SÍ es una oferta de verdad
	 * (LoopEnhancer muestra "−NN%" y la estampilla roja está justificada): el
	 * módulo no marca nada, así que tampoco puede colgarle una nota de preventa.
	 *
	 * @testdox Should leave a real discount untouched, note included.
	 */
	public function test_real_discount_over_the_threshold_stays_untouched(): void {
		// 14.000 → 10.000 = 29%, muy por encima del 10% por defecto.
		$html = $this->filtered( $this->product( StockStatus::ON_BACKORDER, '14000', '10000' ) );

		self::assertSame( self::WC_SALE_HTML, $html );
	}

	/**
	 * El copy es el vocabulario de cara al cliente: se ajusta por filtro, no
	 * editando 472 cards. Y devolver '' es el interruptor de apagado de una
	 * afirmación de precio — que NO puede llevarse por delante la capa accesible.
	 *
	 * @testdox Should let the filter reword or suppress the note without dropping the accessible amount.
	 */
	public function test_filter_owns_the_copy_and_can_suppress_the_note(): void {
		$this->filter_overrides['akibara_preventa_price_note'] = 'Reservando hoy pagas menos';
		$reescrito                                             = $this->filtered();

		self::assertSame( 'Reservando hoy pagas menos', $this->nota( $reescrito ) );

		$this->filter_overrides['akibara_preventa_price_note'] = '   ';
		$suprimido                                             = $this->filtered();

		self::assertStringNotContainsString( 'akb-preventa-alza', $suprimido );
		self::assertStringNotContainsString( '<small', $suprimido, 'Suprimir no puede dejar un elemento vacío en la card.' );
		self::assertStringContainsString( '13.500', $this->lectura( $suprimido ), 'Apagar el copy no apaga el precio vigente.' );
		self::assertStringContainsString( 'akb-price--preventa', $suprimido, 'Ni el des-escalado visual del <ins>.' );
	}

	/**
	 * El texto que llega al DOM lo escapa `esc_html()`, y el importe trae la
	 * entidad `&#36;` de `wc_price()`. Re-escaparla imprimiría «&#36;14.000»
	 * literal en la card — una nota de precio ilegible en las 472 preventas.
	 *
	 * @testdox Should not double encode the currency entity.
	 */
	public function test_currency_entity_is_not_double_encoded(): void {
		$html = $this->filtered();

		self::assertStringNotContainsString( '&amp;#', $html );
		self::assertStringContainsString( 'sube a &#036;14.000', $html );
	}

	/**
	 * Contrato de MARKUP completo contra la forma real de producción. Es el test
	 * que se pone rojo si alguien reordena los nodos, cambia el elemento o mete
	 * la nota dentro del `<ins>`.
	 *
	 * @testdox Should emit the exact production markup for a preventa card.
	 */
	public function test_emits_the_exact_expected_markup(): void {
		$sin_sr = '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
			. '<span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>14.000</bdi></span></del>'
			. ' <ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
			. '<span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>13.500</bdi></span></ins>';

		self::assertSame(
			'<span class="akb-price--preventa">'
				. $sin_sr
				. '<span class="screen-reader-text">Precio de reserva: &#036;13.500.</span>'
				. '<small class="akb-preventa-alza">Al llegar el stock sube a &#036;14.000</small>'
				. '</span>',
			$this->filtered()
		);
	}

	/**
	 * `akibara-search` materializa `price_html` en una columna VARCHAR(1000) y
	 * `DocumentBuilder::build()` recorta a 1000 antes de escribirlo: pasarse
	 * cortaría una etiqueta por la mitad dentro del innerHTML del autocomplete.
	 * Medido: la preventa pasa de 504 a 549 bytes, o sea el margen sigue siendo
	 * del doble. Este test es el que avisa si alguien agranda el copy sin mirar.
	 *
	 * @testdox Should stay well inside the width of the search index price column.
	 */
	public function test_stays_within_the_search_index_price_column(): void {
		self::assertLessThan( 1000, strlen( $this->filtered() ) );
	}

	/**
	 * @testdox Should leave untouched every price html that is not a preventa.
	 *
	 * @dataProvider foreign_cases
	 *
	 * @param string $stock_status Estado de stock crudo.
	 * @param string $type         Tipo de producto WC.
	 * @param string $why          Motivo del contrato (mensaje de fallo).
	 */
	public function test_other_branches_pass_through_untouched( string $stock_status, string $type, string $why ): void {
		$html = $this->filtered( $this->product( $stock_status, '14000', '13500', true, $type ) );

		self::assertSame( self::WC_SALE_HTML, $html, $why );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function foreign_cases(): array {
		return array(
			'disponible en oferta' => array(
				StockStatus::IN_STOCK,
				'simple',
				'Una oferta sobre un producto comprable HOY sí es una rebaja: no lleva nota de llegada.',
			),
			'agotado en oferta'    => array(
				StockStatus::OUT_OF_STOCK,
				'simple',
				'El agotado es dominio de LoopEnhancer (prio 30): acá no se toca.',
			),
			'preventa variable'    => array(
				StockStatus::ON_BACKORDER,
				'variable',
				'En un variable el regular del padre viene vacío y el precio es un RANGO: sin nota.',
			),
		);
	}

	/**
	 * `woocommerce_get_price_html` es un filtro público: cualquiera puede
	 * dispararlo con algo que no sea un WC_Product.
	 *
	 * @testdox Should return the html unchanged when the second argument is not a product.
	 */
	public function test_non_product_argument_is_returned_unchanged(): void {
		$ux = new PreventaUX();

		self::assertSame( self::WC_SALE_HTML, $ux->rewrite_preventa_price_html( self::WC_SALE_HTML, null ) );
		self::assertSame( '', $ux->rewrite_preventa_price_html( null, 'no soy un producto' ) );
	}

	/**
	 * Las dos clases del módulo son CONDICIONALES (sólo existen en una card de
	 * preventa con incentivo bajo el umbral), así que el UCSS de LiteSpeed puede
	 * podarlas si el muestreo cae en una URL sin preventa a la vista. Sin
	 * `.akb-price--preventa` vuelve la estampilla roja de liquidación; sin
	 * `.akb-preventa-alza` la nota queda como texto suelto dentro del precio.
	 *
	 * @testdox Should shield both conditional price classes from UCSS tree shaking.
	 */
	public function test_ucss_allowlist_shields_the_conditional_price_classes(): void {
		$ux = new PreventaUX();
		$ux->register();

		$selectors = $ux->allowlist_ucss_selectors( array() );

		self::assertContains( '.akb-price--preventa', $selectors );
		self::assertContains( '.akb-preventa-alza', $selectors );
		self::assertSame( 20, Filters\has( 'litespeed_ucss_whitelist', array( $ux, 'allowlist_ucss_selectors' ) ) );
	}
}
