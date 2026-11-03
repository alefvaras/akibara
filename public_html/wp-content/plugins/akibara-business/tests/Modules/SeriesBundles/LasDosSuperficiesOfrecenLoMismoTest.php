<?php
/**
 * La ficha y la landing de la misma serie no pueden ofrecer cosas distintas.
 *
 * Hallazgo S-c87d5321: con el interruptor «Incluir tomos a pedido y en
 * preventa» apagado —que es el valor de fábrica—, el armador de la FICHA ni
 * siquiera pintaba los tomos reservables: desaparecían. La landing de esa
 * misma serie los pintaba, dejaba seleccionarlos, los agregaba por su propio
 * endpoint y escribía «Puedes agregarlos juntos igual». Dos respuestas al
 * mismo cliente para el mismo tomo, a un clic de distancia.
 *
 * Lo que el interruptor decide es si esos tomos SUMAN para el tramo de
 * descuento (`discount_eligible`), no si se pueden comprar.
 *
 * REAPERTURA 2026-09-19 (prueba_debil): la versión anterior de este fichero
 * medía las dos puertas leyendo `file_get_contents()` sobre la fuente. Un
 * filtro reintroducido con OTRO nombre —p. ej.
 * `if ( $is_preventa && ! $this->settings->allows_backorder() ) { continue; }`—
 * no contiene la cadena literal que se prohibía y dejaba las aserciones en
 * verde mientras los tomos a pedido volvían a desaparecer de la rejilla.
 * `series_volumes()` y `builder_view_model()` sí necesitan WooCommerce para
 * ARMAR el HTML completo, pero se puede invocar `series_volumes()` sola
 * —con dobles de producto y `wc_get_product`/`get_the_terms` stubeados, igual
 * que ya hace `BuilderWidgetSecurityTest`— y afirmar sobre el ARRAY que
 * devuelve, no sobre el texto de la fuente. Esa es la prueba que de verdad
 * cae si alguien vuelve a esconder el tomo reservable.
 *
 * Las DOS frases de copy (la explicación al cliente y el rótulo del panel)
 * siguen midiéndose por texto: ningún doble puede observar una cadena que
 * PHP nunca ejecuta, y son justo el acuerdo entre dos ficheros que se
 * desalineó la primera vez.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble mínimo de producto para ejercitar `series_volumes()` de punta a
 * punta: además de lo que ya cubre el stub `WC_Product` (stock, tipo,
 * publicación, comprabilidad), este método hidrata título, precio, imagen y
 * stock administrado por tomo.
 */
final class SeriesParityProduct extends \WC_Product {
	public bool $visible = true;
	public string $current_price = '10000';

	public function is_visible(): bool {
		return $this->visible;
	}

	public function get_price(): string {
		return $this->current_price;
	}

	public function get_name(): string {
		return 'Tomo ' . $this->akb_id;
	}

	public function get_price_html(): string {
		return '$' . $this->current_price;
	}

	/**
	 * @param string               $size Tamaño de imagen WC (ignorado, sin galería real).
	 * @param array<string,string> $attr Atributos HTML (ignorados).
	 */
	public function get_image( $size = 'woocommerce_thumbnail', $attr = array() ): string {
		unset( $size, $attr );
		return '<img>';
	}

	public function get_image_id(): int {
		return 0;
	}

	public function managing_stock(): bool {
		return false;
	}

	public function get_stock_quantity(): ?int {
		return null;
	}
}

final class LasDosSuperficiesOfrecenLoMismoTest extends TestCase {

	private const TERM_ID = 378;

	/** @var array<int,SeriesParityProduct> */
	private array $products = array();

	/** Valor devuelto por `Settings::allows_backorder()` en cada escenario. */
	private string $allow_backorder = 'no';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

		$this->products        = array();
		$this->allow_backorder = 'no';

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				$options = array(
					Settings::OPTION_ENABLED         => 'yes',
					Settings::OPTION_BYP             => (string) json_encode(
						array(
							(string) self::TERM_ID => array(
								'enabled' => true,
								'tiers'   => array( array( 'min' => 2, 'pct' => 10 ) ),
							),
						)
					),
					Settings::OPTION_EXCLUDE_CATS    => array(),
					Settings::OPTION_ALLOW_BACKORDER => $this->allow_backorder,
				);
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( int $product_id, string $taxonomy ) {
				if ( 'pa_serie' !== $taxonomy || ! isset( $this->products[ $product_id ] ) ) {
					return array();
				}
				$term           = new \WP_Term( self::TERM_ID, 'serie', 'Serie de prueba' );
				$term->taxonomy = 'pa_serie';
				return array( $term );
			}
		);
		// Devolver directo los IDs sembrados salta la rama SQL (`false === $ids`)
		// por completo: no hace falta doblar `$wpdb` para esta prueba.
		Functions\when( 'get_transient' )->alias( fn(): array => array_keys( $this->products ) );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->products[ $id ] ?? null );
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.cl/producto/' . $id . '/' );
		// pending_launch() (Availability) lo consulta para distinguir «a pedido»
		// de «preventa»; cualquier valor sirve acá, no es lo que se mide.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// StoreTime::zone() cae a UTC si `wp_timezone` no existe, pero corrida
		// junto al resto de la suite otro test ya la dejó "conocida" para Brain
		// Monkey/Patchwork (contagio) y una llamada sin expectativa revienta con
		// MissingFunctionExpectations; se stubea acá por eso, no porque importe
		// la zona exacta para lo que mide este fichero.
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function product( int $id, string $stock_status ): SeriesParityProduct {
		$product                    = new SeriesParityProduct( $stock_status, array(), true );
		$product->akb_id            = $id;
		$product->akb_regular_price = '10000';
		$this->products[ $id ]      = $product;
		return $product;
	}

	/** @return array<int,array<string,mixed>> Indexado por `id`. */
	private function series_volumes_by_id( int $viewed_id, int $count = 10 ): array {
		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$builder   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );

		$method = new \ReflectionMethod( BuilderWidget::class, 'series_volumes' );
		$method->setAccessible( true );
		/** @var array<int,array<string,mixed>> $volumes */
		$volumes = $method->invoke( $builder, $viewed_id, $count, self::TERM_ID );

		$by_id = array();
		foreach ( $volumes as $vol ) {
			$by_id[ (int) $vol['id'] ] = $vol;
		}
		return $by_id;
	}

	/**
	 * @testdox El tomo a pedido sigue en la rejilla y se puede agregar, pero sólo cuenta para el descuento cuando el interruptor lo permite.
	 */
	public function test_el_tomo_a_pedido_no_desaparece_y_el_interruptor_solo_mueve_el_descuento(): void {
		$disponible = $this->product( 501, 'instock' );
		$a_pedido   = $this->product( 502, 'onbackorder' );

		// Escenario del hallazgo: interruptor apagado (valor de fábrica).
		$this->allow_backorder = 'no';
		$volumes               = $this->series_volumes_by_id( $disponible->get_id() );

		self::assertCount(
			2,
			$volumes,
			'El tomo a pedido volvió a desaparecer de la rejilla de la ficha: esto es exactamente S-c87d5321.'
		);
		self::assertTrue( $volumes[ $disponible->get_id() ]['is_purchasable'] );
		self::assertTrue(
			$volumes[ $a_pedido->get_id() ]['is_purchasable'],
			'Con el interruptor apagado el tomo a pedido debe seguir siendo comprable: el interruptor no decide esto.'
		);
		self::assertTrue( $volumes[ $disponible->get_id() ]['discount_eligible'] );
		self::assertFalse(
			$volumes[ $a_pedido->get_id() ]['discount_eligible'],
			'Con el interruptor apagado, el tomo a pedido no debe sumar para el tramo de descuento.'
		);

		// Con el interruptor encendido, el mismo tomo se vuelve elegible para el
		// descuento SIN cambiar su visibilidad ni su comprabilidad: fija que lo
		// único que mueve es `discount_eligible`.
		$this->allow_backorder = 'yes';
		$volumes               = $this->series_volumes_by_id( $disponible->get_id() );

		self::assertCount( 2, $volumes );
		self::assertTrue( $volumes[ $a_pedido->get_id() ]['is_purchasable'] );
		self::assertTrue(
			$volumes[ $a_pedido->get_id() ]['discount_eligible'],
			'Con el interruptor encendido, el tomo a pedido debe sumar para el tramo de descuento.'
		);
	}

	/** @testdox «Se puede agregar» se decide igual en las dos superficies. */
	public function test_la_puerta_de_agregar_es_la_misma(): void {
		$ficha   = $this->fuente( 'BuilderWidget.php' );
		$landing = $this->fuente( 'LandingOffer.php' );

		// Sin relleno de alineación WPCS: `phpcbf` o renombrar la variable vecina
		// (`$discount_eligible`/`$can_...`) cambia los espacios sin tocar el
		// comportamiento, y una aserción con el relleno exacto se rompería sola.
		self::assertStringContainsString( 'resolve_catalog_series( $vol_product, $term_id )', $ficha );
		self::assertStringContainsString( 'resolve_catalog_series( $product, $term_id )', $landing );

		// Y el descuento sigue siendo la OTRA puerta, en las dos.
		foreach ( array( $ficha, $landing ) as $src ) {
			self::assertStringContainsString( 'resolve_eligible_series_for_term', $src );
		}
	}

	/** @testdox Agregar desde la ficha no exige elegibilidad promocional, como en la landing. */
	public function test_el_endpoint_de_la_ficha_no_exige_el_descuento(): void {
		$ficha = $this->fuente( 'BuilderWidget.php' );

		self::assertStringNotContainsString(
			"|| null === \$this->collector->resolve_eligible_series_for_term( \$selected_product, \$series_id ) ) {",
			$ficha,
			'El servidor volvía a descartar en silencio los tomos que el cliente acababa de elegir.'
		);
	}

	/**
	 * Con el interruptor apagado, la ficha explica lo mismo que la landing.
	 *
	 * Se mide por texto a propósito: es un acuerdo de COPY entre dos ficheros
	 * PHP que ningún doble puede observar (no hay comportamiento que ejecutar,
	 * sólo una cadena que el cliente lee). Es justo lo que se desalineó la
	 * primera vez.
	 *
	 * @testdox Con el interruptor apagado, la ficha explica lo mismo que la landing.
	 */
	public function test_las_dos_dicen_la_misma_frase(): void {
		$frase = 'Cuentan los tomos disponibles ahora; los a pedido no. Puedes agregarlos juntos igual.';

		self::assertStringContainsString( $frase, $this->fuente( 'BuilderWidget.php' ) );
		self::assertStringContainsString( $frase, $this->fuente( 'LandingOffer.php' ) );
	}

	/** @testdox El interruptor del panel dice lo que de verdad hace. */
	public function test_el_panel_no_promete_otra_cosa(): void {
		$panel = $this->fuente( 'Admin/AdminSection.php' );

		self::assertStringContainsString( 'CUENTAN para el tramo de descuento', $panel );
		self::assertStringNotContainsString( 'también se pueden seleccionar', $panel, 'Seleccionarlos ya no depende de este interruptor.' );
	}

	private function fuente( string $archivo ): string {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/' . $archivo );
		$tpl = dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php';
		if ( 'BuilderWidget.php' === $archivo && file_exists( $tpl ) ) {
			$src .= (string) file_get_contents( $tpl );
		}
		return $src;
	}
}
