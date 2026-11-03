<?php
/**
 * Pruebas del bloque de pack en las landings `pa_serie`.
 *
 * ── Por qué setUp() declara TODOS los guards de contexto ─────────────────────
 * Brain Monkey define la función en el runtime de PHP la primera vez que un test
 * la mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`: lo que se limpia
 * es la expectativa, no el símbolo. Un test que llame a `is_tax()` sin stubearla
 * no recibe el comportamiento nativo — recibe el stub vacío, que devuelve null.
 * Traducido a esta suite: `queried_series()` daría null y todos los asserts de
 * render fallarían… pero sólo en las permutaciones donde otro test corre antes.
 * Verde en orden natural, rojo con `--order-by=random`, que es la peor clase de
 * verde. Por eso acá se fijan defaults DETERMINISTAS de todo lo que los guards
 * tocan, aunque el test concreto no lo use (misma disciplina que
 * `SeriesFactsTest`).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\LandingOffer;
use Akibara\Business\Modules\SeriesBundles\SelectionCart;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble de `$wpdb` que devuelve el conteo de tomos vendibles y cuenta consultas.
 *
 * El contador no es decorativo: el contrato de rendimiento del bloque ("una
 * consulta por término, jamás una por tomo") sólo se puede observar contando.
 */
final class LandingOfferWpdb {

	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';

	/** Consultas ejecutadas (SQL ya interpolado). */
	public array $queries = array();

	/** Tomos vendibles que devuelve el COUNT; null = error de base de datos. */
	public ?int $count = 17;

	/**
	 * Ids que devuelve la lista de tomos vendibles de la serie; null = error de
	 * base de datos. Es la fuente TERM-DRIVEN del bloque: ya no se mira el loop.
	 *
	 * @var array<int, int>|null
	 */
	public ?array $ids = array();

	/**
	 * @param string $query Consulta preparada.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;

		return null === $this->count ? null : (string) $this->count;
	}

	/**
	 * Los ids salen como strings, igual que los devuelve `$wpdb`: el casteo es
	 * responsabilidad del código bajo prueba.
	 *
	 * @param string $query Consulta preparada.
	 * @return array<int, string>|null
	 */
	public function get_col( string $query ) {
		$this->queries[] = $query;

		return null === $this->ids ? null : array_map( 'strval', $this->ids );
	}

	/**
	 * Imita el quoting real de WordPress: lo que se prueba es qué literales
	 * viajan al SQL (el término y la taxonomía), así que un doble que devolviera
	 * el formato sin sustituir escondería el defecto que importa.
	 *
	 * @param string $query   Consulta con placeholders.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		$sql = $query;
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 ) ?? $sql;
		}

		return $sql;
	}
}

/**
 * Tomo de la grilla.
 *
 * EXTIENDE el `WC_Product` de `wp-stubs.php` y no es una clase suelta porque
 * `Settings::is_stock_eligible()` y `StockStatus::is_instock()` tipan el
 * argumento contra `WC_Product`: un doble que no herede jamás llegaría al
 * chequeo de stock. Sólo agrega `get_name()`, que el stub compartido no expone
 * y que este camino necesita para el texto del enlace.
 */
final class LandingOfferProduct extends \WC_Product {

	private string $name;

	public function __construct( int $id, string $name, string $stock_status = 'instock', bool $purchasable = true ) {
		parent::__construct( $stock_status, array(), $purchasable );
		$this->akb_id = $id;
		$this->name   = $name;
		$this->akb_regular_price = '10990';
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_price(): string {
		return '9990';
	}
}

final class LandingOfferTest extends TestCase {

	private const TERM_ID = 378;

	/** @var mixed */
	private $original_wpdb = null;

	/** @var mixed */
	private $original_wp_query = null;

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, LandingOfferProduct> */
	private array $products = array();

	/**
	 * Categorías `product_cat` por producto, para el guard de exclusión.
	 *
	 * @var array<int, array<int, \WP_Term>>
	 */
	private array $product_cats = array();

	private LandingOfferWpdb $wpdb;

	/**
	 * Config BYP de referencia: dos tramos, tal como los persiste el admin
	 * (claves string, porque viene de `json_decode` de la opción).
	 */
	private const TWO_TIERS = array(
		'enabled' => true,
		'tiers'   => array(
			array(
				'min' => 3,
				'pct' => 10,
			),
			array(
				'min' => 6,
				'pct' => 15,
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->original_wp_query = $GLOBALS['wp_query'] ?? null;
		$this->original_get      = $_GET;
		$_GET                    = array();

		$this->wpdb         = new LandingOfferWpdb();
		$GLOBALS['wpdb']    = $this->wpdb;
		$this->product_cats = array();

		// Estado por defecto: la landing de una serie con pack habilitado y
		// catálogo suficiente, que es el único request donde el bloque debe salir.
		$this->options = array(
			'akb_series_bundles_enabled'         => 'yes',
			'akb_series_bundles_byp'             => (string) json_encode( array( (string) self::TERM_ID => self::TWO_TIERS ) ),
			'akb_series_bundles_exclude_cats'    => array(),
			'akb_series_bundles_allow_backorder' => 'no',
		);

		// 17 tomos vendibles, como The Climber. El universo lo define la SERIE
		// (`SeriesDepth::sellable_ids()`), no lo que la página esté mostrando.
		$this->set_series_volumes( 17 );

		// El loop se puebla igual, con DOS tomos: ninguna decisión del bloque puede
		// depender de él, y varios tests lo comprueban dejándolo vacío o con puros
		// agotados.
		$this->set_loop( array( 22254, 22251 ) );

		// Guards de contexto.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->series_term() );

		// Opciones (Settings lee las cuatro del módulo).
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);

		// Todo producto de la landing pertenece a la serie consultada, y sólo los
		// que el test marque tienen categoría. Default determinista y NO opcional:
		// otras suites mockean `get_the_terms` y dejan el símbolo definido para todo
		// el proceso.
		Functions\when( 'get_the_terms' )->alias(
			function ( int $product_id, string $taxonomy ) {
				if ( 'product_cat' === $taxonomy ) {
					return $this->product_cats[ $product_id ] ?? array();
				}
				if ( 'pa_serie' !== $taxonomy ) {
					return array();
				}
				return array( $this->series_term() );
			}
		);
		// La usa `EligibilityGuard` sobre los términos de `product_cat`. Default
		// determinista por la misma razón que los guards de contexto de arriba.
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( array $list, string $field ): array {
				return array_map( static fn( $item ) => $item->{$field} ?? null, $list );
			}
		);
		Functions\when( 'wc_get_product' )->alias(
			fn( int $product_id ) => $this->products[ $product_id ] ?? null
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $product_id ): string => 'https://akibara.cl/producto-' . $product_id . '/'
		);

		// Escapes.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $value ) )
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/the-climber/' );
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ) {
				// Las dos formas que usa el módulo: `add_query_arg( array() )`
				// devuelve la petición actual, y la de tres argumentos sustituye
				// un parámetro en una URL.
				if ( is_array( $key ) && null === $value && null === $url ) {
					return (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
				}
				$partes = explode( '?', (string) $url, 2 );
				$query  = array();
				if ( isset( $partes[1] ) ) {
					parse_str( $partes[1], $query );
				}
				$query[ (string) $key ] = (string) $value;

				return $partes[0] . '?' . http_build_query( $query );
			}
		);
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => 'https://akibara.cl/' . $page . '/'
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wc_query_string_form_fields' )->justReturn( '' );
		Functions\when( 'disabled' )->alias(
			static function ( $disabled, $current = true, bool $display = true ): string {
				$result = $disabled === $current ? ' disabled="disabled"' : '';
				if ( $display ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doble de la API de WP.
				}
				return $result;
			}
		);
	}

	/**
	 * Corre la redirección del literal viejo y devuelve los saltos que emitió.
	 *
	 * `wp_safe_redirect()` va seguido de `exit`, que en la suite mataría al
	 * runner: se corta el flujo con una excepción y se atrapa aquí, así que lo
	 * que el test observa es exactamente lo que el navegador recibiría.
	 *
	 * @return array<int, array{url: string, status: int}>
	 */
	private function run_legacy_redirect(): array {
		$saltos = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url, int $status = 302 ) use ( &$saltos ): bool {
				$saltos[] = array(
					'url'    => $url,
					'status' => $status,
				);

				throw new \RuntimeException( 'akb-redirect-halt' );
			}
		);

		try {
			$this->offer()->redirect_legacy_stock_filter();
		} catch ( \RuntimeException $e ) {
			if ( 'akb-redirect-halt' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $saltos;
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		if ( null === $this->original_wp_query ) {
			unset( $GLOBALS['wp_query'] );
		} else {
			$GLOBALS['wp_query'] = $this->original_wp_query;
		}
		$_GET = $this->original_get;

		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Landing CON descuento
	// -------------------------------------------------------------------------

	/**
	 * @testdox Should publish every reachable tier on a series landing with a pack.
	 */
	public function test_landing_with_discount_publishes_the_tier_ladder(): void {
		$html = $this->render();

		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( 'Desde 3 tomos · 10% dto.', $html );
		self::assertStringContainsString( 'Desde 6 tomos · 15% dto.', $html );
	}

	/**
	 * Este test fijaba `value="onbackorder"` y por eso el filtro pudo estar roto
	 * en verde: el chip mandaba el literal INTERNO de WooCommerce mientras la
	 * faceta del catálogo publica el COMERCIAL. «A pedido 78» mostraba cero
	 * tomos en todas las series (One Piece y Naruto, 2026-09-05). Ahora se fija
	 * la conducta —el valor tiene que ser el que entiende la faceta— y no el
	 * literal.
	 *
	 * @testdox Los chips emiten el vocabulario de la faceta y llevan los conteos del catálogo.
	 */
	public function test_stock_chips_use_catalog_counts_and_real_get_parameters(): void {
		$this->products[22201] = new LandingOfferProduct( 22201, 'The Climber 01', 'onbackorder' );
		$this->products[22202] = new LandingOfferProduct( 22202, 'The Climber 02', 'outofstock', false );
		$_GET[ LandingOffer::STOCK_FILTER_PARAM ] = Availability::A_PEDIDO;

		$html = $this->render();

		// «productos» y no «tomos»: el chip cuenta las CARDS que quedan tras
		// filtrar, y una serie con dos ediciones del mismo tomo tiene más cards
		// que tomos. El bloque de datos de la landing sí habla en tomos.
		self::assertStringContainsString( 'aria-label="17 productos"', $html );
		self::assertStringContainsString( 'aria-label="15 productos"', $html );
		self::assertStringContainsString( 'aria-label="1 productos"', $html );
		self::assertStringContainsString( 'name="_disponibilidad" value="' . Availability::A_PEDIDO . '"', $html );
		self::assertMatchesRegularExpression( '/akb-sb-landing__filter is-active"[^>]*name="_disponibilidad" value="' . Availability::A_PEDIDO . '"/s', $html );
		self::assertStringNotContainsString( 'value="onbackorder"', $html, 'La faceta no conoce el literal interno de WooCommerce: con él el chip no filtra nada.' );
		self::assertStringContainsString( 'id="completar-coleccion"', $html, 'El deep-link de Mi cuenta sigue apuntando al riel.' );
	}

	/**
	 * QAM-4 (QA integral 2026-09-24): el formulario de los chips iba a
	 * `#completar-coleccion`, el fragmento que ABRE el modo de selección, y quien
	 * sólo filtraba por «Disponibles» aterrizaba con el panel de elegir tomos
	 * ocupando el 40 % del teléfono.
	 *
	 * @testdox Los chips de disponibilidad vuelven a la cabecera del riel sin abrir la selección.
	 */
	public function test_stock_chips_anchor_does_not_open_the_selection_mode(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression(
			'/<form class="akb-sb-landing__filters"[^>]*action="[^"]*#' . LandingOffer::FILTERS_FRAGMENT . '"/',
			$html,
			'El formulario de los chips vuelve a su propia ancla.'
		);
		self::assertDoesNotMatchRegularExpression(
			'/<form class="akb-sb-landing__filters"[^>]*action="[^"]*#' . LandingOffer::FRAGMENT . '"/',
			$html,
			'El fragmento que abre la selección no puede ser el destino de un filtro.'
		);
		self::assertMatchesRegularExpression(
			'/<div class="akb-sb-landing__head" id="' . LandingOffer::FILTERS_FRAGMENT . '">/',
			$html,
			'El ancla existe en la página: sin ella el navegador no desplaza.'
		);
		self::assertNotSame( LandingOffer::FRAGMENT, LandingOffer::FILTERS_FRAGMENT );
	}

	/**
	 * El candado de fondo: los tres chips tienen que hablar el MISMO vocabulario
	 * que publica el índice del catálogo (`wp_akb_search_facet_index`, faceta
	 * `disponibilidad`), que en producción sólo contiene `instock`, `a_pedido` y
	 * `outofstock`. Cualquier otro valor produce una lista vacía bajo un chip
	 * que promete tomos.
	 *
	 * @testdox Ningún chip puede emitir un valor que la faceta no publique.
	 */
	public function test_every_chip_speaks_the_facet_vocabulary(): void {
		$this->products[22201] = new LandingOfferProduct( 22201, 'The Climber 01', 'onbackorder' );
		$this->products[22202] = new LandingOfferProduct( 22202, 'The Climber 02', 'outofstock', false );

		$html = $this->render();

		$publicados = array( StockStatus::IN_STOCK, Availability::A_PEDIDO, StockStatus::OUT_OF_STOCK );
		preg_match_all( '/name="_disponibilidad" value="([^"]*)"/', $html, $m );
		self::assertNotEmpty( $m[1], 'Los chips tienen que emitir el parámetro de la faceta.' );
		foreach ( $m[1] as $valor ) {
			self::assertContains(
				$valor,
				$publicados,
				sprintf( 'El chip manda «%s» y la faceta sólo publica %s: ese chip mostraría cero tomos.', $valor, implode( ', ', $publicados ) )
			);
		}
	}

	/**
	 * @testdox Un enlace compartido con el literal viejo sigue marcando el chip correcto.
	 */
	public function test_a_shared_link_with_the_old_literal_still_highlights_the_chip(): void {
		$this->products[22201] = new LandingOfferProduct( 22201, 'The Climber 01', 'onbackorder' );
		$_GET[ LandingOffer::STOCK_FILTER_PARAM ] = 'onbackorder';

		$html = $this->render();

		self::assertMatchesRegularExpression(
			'/akb-sb-landing__filter is-active"[^>]*name="_disponibilidad" value="' . Availability::A_PEDIDO . '"/s',
			$html,
			'Los enlaces que ya circulan traen el literal viejo: el chip «A pedido» tiene que quedar marcado igual.'
		);
	}

	/**
	 * El resaltado del chip no basta: sin la redirección, el enlace viejo deja
	 * el chip «A pedido» encendido sobre una rejilla VACÍA, porque el índice del
	 * buscador no conoce `onbackorder`. Medido en producción el 2026-09-05 sobre
	 * `/serie/frieren/`: `a_pedido` sirve 15 tomos y `onbackorder`, cero.
	 *
	 * @testdox Un enlace compartido con el literal viejo se manda a la URL que sí muestra tomos.
	 */
	public function test_a_shared_link_with_the_old_literal_is_sent_to_the_url_that_serves_volumes(): void {
		$_GET[ LandingOffer::STOCK_FILTER_PARAM ] = 'onbackorder';
		$_SERVER['REQUEST_URI']                   = '/serie/frieren/?_disponibilidad=onbackorder';

		$saltos = $this->run_legacy_redirect();

		self::assertCount( 1, $saltos, 'El enlace viejo tiene que ir a otra URL, no servirse tal cual.' );
		self::assertStringContainsString( '_disponibilidad=' . Availability::A_PEDIDO, $saltos[0]['url'] );
		self::assertStringNotContainsString( 'onbackorder', $saltos[0]['url'] );
		self::assertSame( 301, $saltos[0]['status'], 'Permanente: consolida en una sola URL lo que hoy son dos.' );
	}

	/**
	 * @testdox El valor bueno y los archivos del panel no se redirigen.
	 */
	public function test_the_canonical_value_is_never_redirected(): void {
		foreach ( array( Availability::A_PEDIDO, 'instock', 'outofstock', '', 'basura' ) as $valor ) {
			$_GET[ LandingOffer::STOCK_FILTER_PARAM ] = $valor;
			$_SERVER['REQUEST_URI']                   = '/serie/frieren/?_disponibilidad=' . $valor;

			$saltos = $this->run_legacy_redirect();

			self::assertSame( array(), $saltos, '«' . $valor . '» no tiene que redirigir: sería un salto por nada, o un bucle.' );
		}
	}

	/** @testdox A preorder remains selectable without BYP and is explicitly excluded from discount math. */
	public function test_card_selector_separates_purchase_from_discount_eligibility(): void {
		$this->options['akb_series_bundles_byp'] = '{}';
		$product = new LandingOfferProduct( 22201, 'The Climber 01', 'onbackorder' );
		$this->products[22201] = $product;
		$previous = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;

		ob_start();
		$this->offer()->render_volume_selector();
		$html = (string) ob_get_clean();

		if ( null === $previous ) {
			unset( $GLOBALS['product'] );
		} else {
			$GLOBALS['product'] = $previous;
		}

		self::assertStringContainsString( 'data-akb-sb-landing-vol', $html );
		self::assertStringContainsString( 'data-status="onbackorder"', $html );
		self::assertStringContainsString( 'data-discount-eligible="0"', $html );
		self::assertStringNotContainsString( 'disabled="disabled"', $html );
	}

	/**
	 * Un cliente puede leer el tramo en la landing y en la ficha en el mismo
	 * minuto. Con el `sprintf` copiado en las dos, el día que alguien ajuste la
	 * redacción en una, el mismo descuento quedaría descrito con palabras
	 * distintas: nadie lo reporta como bug y resta confianza en el número.
	 *
	 * @testdox Should describe a tier with the very same words the product page pills use.
	 */
	public function test_tier_wording_has_a_single_source(): void {
		$label = ( new VolumeTierResolver() )->tier_label( 3, 10 );

		self::assertStringContainsString( $label, $this->render() );

		$builder = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php'
		);
		self::assertStringContainsString( 'tier_label(', $builder );
		self::assertStringNotContainsString( "'Desde %d tomos", $builder );
	}

	/**
	 * @testdox Should state that the discount applies by itself in the cart.
	 */
	public function test_landing_states_the_cart_mechanic(): void {
		$html = $this->render();

		self::assertStringContainsString(
			'Se aplica solo al sumarlos al carrito.',
			$html
		);
		self::assertStringContainsString(
			'Toca una tarjeta —portada, título o «Seleccionar»— para marcar o desmarcar ese tomo.',
			$html
		);
		// QA-MOV-3: la frase de la vista rápida va en su propio tramo, que la hoja
		// sólo muestra desde QuickView::MEDIA, y nombra el botón con su rótulo.
		self::assertStringContainsString(
			'<span class="akb-sb-landing__qv-hint">Usa «Vista rápida» para ver sus detalles. </span>Tu selección se conserva al cambiar de página.',
			$html
		);
		self::assertStringContainsString(
			'Cada tomo distinto cuenta una vez; el descuento aplica a una unidad de cada tomo elegible.',
			$html
		);
		// El atributo lo consume el probe de release, no el JS del riel: es el
		// ancla con la que `browser-probe.mjs` mide el resumen de la promoción.
		self::assertStringContainsString( 'data-akb-sb-landing-summary aria-live="polite" aria-atomic="true"', $html );
		self::assertStringContainsString( 'role="status" aria-live="polite"', $html );
	}

	/**
	 * QA integral 2026-09-24 (QA-MOV-3): en el teléfono el tema no pinta el botón
	 * de vista rápida y las instrucciones lo mencionaban igual. La frase se ve
	 * con la MISMA media que decide si el botón existe.
	 *
	 * @testdox Should show the quick view hint only where the quick view button exists.
	 */
	public function test_quick_view_hint_follows_the_quick_view_media(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/series-bundles.css' );

		self::assertMatchesRegularExpression( '/\n\.akb-sb-landing__qv-hint \{\s*display: none;\s*\}/', $css, 'Oculta por defecto (teléfono).' );
		self::assertStringContainsString(
			'@media ' . \Akibara\Business\Modules\QuickView\QuickView::MEDIA . " {\n\t.akb-sb-landing__qv-hint {\n\t\tdisplay: inline;",
			$css,
			'Visible desde la media de QuickView.'
		);
	}

	/**
	 * UNA barra (auditoría del tema UM-1; revisión B3-R1, 2026-09-23). El riel en
	 * dos filas medía 204 px en móvil y la primera portada seguía bajo la barra
	 * inferior. Título y disparador, chips y botón van en ese orden en el DOM, que
	 * es el orden en que se ven en móvil y en escritorio: el foco y el lector de
	 * pantalla recorren lo mismo que se ve, sin `order` de CSS.
	 *
	 * @testdox The rail is one bar whose document order is title, disclosure, availability chips and button.
	 */
	public function test_rail_is_one_bar_in_visual_order(): void {
		$html = $this->render();

		$head_start  = strpos( $html, '<div class="akb-sb-landing__head"' );
		$panel_start = strpos( $html, 'data-akb-sb-landing-panel' );
		self::assertNotFalse( $head_start );
		self::assertNotFalse( $panel_start );
		$head = substr( $html, $head_start, $panel_start - $head_start );

		$orden = array(
			'título'      => strpos( $head, 'class="akb-sb-landing__title"' ),
			'desplegable' => strpos( $head, '<details class="akb-sb-landing__rules">' ),
			'chips'       => strpos( $head, '<form class="akb-sb-landing__filters"' ),
			'botón'       => strpos( $head, 'data-akb-sb-landing-start' ),
		);
		foreach ( $orden as $pieza => $posicion ) {
			self::assertNotFalse( $posicion, "La barra lleva {$pieza}." );
		}
		self::assertSame( $orden, array_filter( $orden, 'is_int' ) );
		$ordenadas = $orden;
		asort( $ordenadas );
		self::assertSame( array_keys( $orden ), array_keys( $ordenadas ), 'Orden del DOM = orden visual.' );
		self::assertStringNotContainsString( 'akb-sb-landing__terms', $html, 'La fila aparte de tramos ya no existe.' );
	}

	/**
	 * Cerrado, el riel dice el descuento máximo (el del tramo más alto: 15% en
	 * esta serie); abierto, los tramos y las condiciones. Todo sigue en el HTML.
	 *
	 * @testdox The disclosure trigger states the highest discount and opens the tiers and the conditions.
	 */
	public function test_disclosure_states_the_highest_discount_and_holds_tiers_and_conditions(): void {
		$html = $this->render();

		self::assertStringContainsString(
			'<summary class="akb-sb-landing__rules-toggle"><strong class="akb-sb-landing__hook">Hasta 15% de descuento</strong> <span class="akb-sb-landing__rules-label">Cómo funciona</span></summary>',
			$html
		);
		self::assertMatchesRegularExpression(
			'/<\/summary>\s*<div class="akb-sb-landing__rules-body">\s*<div class="akb-sb-landing__tiers"[^>]*>.*Desde 3 tomos · 10% dto\..*Se aplica solo al sumarlos al carrito\..*Cada tomo distinto cuenta una vez.*<\/div>\s*<\/details>/s',
			$html
		);
		self::assertDoesNotMatchRegularExpression( '/<details[^>]*\sopen/', $html, 'Cerrado por defecto: abierto no ahorra nada.' );
	}

	/**
	 * @testdox The discount hook and the disclosure label are the owner's copy, with the percentage in its marker and never through sprintf.
	 */
	public function test_hook_and_label_are_the_owners_copy(): void {
		$this->options[ LandingOffer::OPTION_OFFER_HOOK ]   = 'Ahorra hasta {pct}% armando tu pack';
		$this->options[ LandingOffer::OPTION_RULES_TOGGLE ] = 'Condiciones';
		$html = $this->render();
		self::assertStringContainsString( '<strong class="akb-sb-landing__hook">Ahorra hasta 15% armando tu pack</strong>', $html );
		self::assertStringContainsString( '<span class="akb-sb-landing__rules-label">Condiciones</span>', $html );

		// Sin marcador sale tal cual: el número no se inventa al final.
		$this->options[ LandingOffer::OPTION_OFFER_HOOK ] = 'Descuento por pack';
		self::assertStringContainsString( '<strong class="akb-sb-landing__hook">Descuento por pack</strong>', $this->render() );

		// Un «%» suelto del dueño no es un formato: no rompe la landing.
		$this->options[ LandingOffer::OPTION_OFFER_HOOK ] = '100% manga, hasta {pct}%';
		self::assertStringContainsString( '<strong class="akb-sb-landing__hook">100% manga, hasta 15%</strong>', $this->render() );

		$this->options[ LandingOffer::OPTION_OFFER_HOOK ]   = '';
		$this->options[ LandingOffer::OPTION_RULES_TOGGLE ] = '';
		$html = $this->render();
		self::assertStringContainsString( '>Hasta 15% de descuento</strong>', $html );
		self::assertStringContainsString( '>' . LandingOffer::DEFAULT_RULES_TOGGLE . '</span></summary>', $html );
	}

	/**
	 * @testdox Should use a real button for selection and keep the product link only as a no-script fallback.
	 */
	public function test_cta_is_a_button_with_a_no_script_product_fallback(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression(
			'/<button\s+type="button"\s+class="akb-sb-landing__cta"[^>]*data-akb-sb-landing-start/s',
			$html
		);
		self::assertDoesNotMatchRegularExpression(
			'/<a[^>]*class="akb-sb-landing__cta"/s',
			$html
		);
		self::assertStringContainsString(
			'https://akibara.cl/producto-22201/#' . BuilderWidget::TITLE_ID_PREFIX . self::TERM_ID,
			$html
		);
	}

	/**
	 * El bloque ENTERO —que aparezca, qué umbral anuncia y a dónde lleva— es un
	 * hecho de la SERIE: tiene que ser idéntico en /page/1/ y en /page/3/. Si
	 * dependiera del subconjunto mostrado, el PJAX del buscador (que reemplaza
	 * `ul.products` pero no este bloque) dejaría la misma URL sirviendo dos DOM
	 * distintos según cómo se llegó: quien entra por la paginación lo ve —sobrevive
	 * de la página anterior— y quien hace F5 no.
	 *
	 * @testdox Should render the very same block on a deep page of the archive.
	 */
	public function test_offer_does_not_depend_on_the_page_being_shown(): void {
		$first = $this->render();

		// Última página de una serie larga: sólo quedan agotados a la vista. Es el
		// caso medido (Claymore: 8 instock + 19 agotados con posts_per_page 24).
		$this->set_loop( array() );

		$deep = $this->render();

		self::assertSame( $first, $deep, 'Mismo término, mismo bloque: byte a byte.' );
		self::assertStringContainsString( 'Desde 3 tomos · 10% dto.', $deep );
		self::assertStringContainsString( 'Desde 6 tomos · 15% dto.', $deep );
		self::assertStringContainsString( 'akb-sb-landing__cta', $deep );
	}

	/**
	 * La misma independencia, por el otro lado: el bloque no depende siquiera de
	 * que exista un `$wp_query` con posts. Bajo la faceta «Disponibilidad =
	 * Agotado» el main query queda con puros `outofstock` y el umbral de la serie
	 * no cambió.
	 *
	 * @testdox Should render even when the current query carries no usable posts at all.
	 */
	public function test_offer_survives_a_query_without_posts(): void {
		unset( $GLOBALS['wp_query'] );

		$html = $this->render();

		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( 'akb-sb-landing__cta', $html );
		self::assertDoesNotMatchRegularExpression( '/<a[^>]*class="akb-sb-landing__cta"/s', $html );
	}

	/**
	 * Contrato de rendimiento: decidir si hay pack cuesta UNA consulta por
	 * término, no una por tomo. La landing ya carga 17–24 productos.
	 *
	 * @testdox Should resolve the offer with a single indexed query, never one per volume.
	 */
	public function test_offer_costs_one_query_per_term(): void {
		$this->render();

		self::assertCount( 1, $this->wpdb->queries );
		self::assertStringContainsString( 'DISTINCT p.ID', $this->wpdb->queries[0] );
		self::assertStringContainsString( 'tt.term_id = ' . self::TERM_ID, $this->wpdb->queries[0] );
		self::assertStringContainsString( "'pa_serie'", $this->wpdb->queries[0] );
		self::assertStringContainsString( 'ORDER BY p.ID ASC', $this->wpdb->queries[0], 'El universo tiene que ser estable entre páginas.' );
	}

	/**
	 * El recorrido no puede escalar con el largo de la serie: en One Piece son 107
	 * tomos y lo único que hay que saber es si se llega al tramo más alto (6).
	 * Hidratar los 107 para contestar eso sería pagar el peor caso en cada
	 * pageload de una landing que ni siquiera está cacheada hoy.
	 *
	 * @testdox Should stop hydrating volumes as soon as the highest tier is reached.
	 */
	public function test_walk_stops_at_the_highest_tier(): void {
		$hydrated = array();
		Functions\when( 'wc_get_product' )->alias(
			function ( int $product_id ) use ( &$hydrated ) {
				$hydrated[] = $product_id;
				return $this->products[ $product_id ] ?? null;
			}
		);

		$this->set_series_volumes( 107 );
		$this->render();

		// El tramo más alto de la config de referencia es 6.
		self::assertCount( 6, $hydrated );
	}

	/**
	 * El memo de `SeriesDepth` es lo que hace que ficha y landing compartan el
	 * conteo. Sin instancia común serían dos COUNT idénticos por request.
	 *
	 * @testdox Should count the series once per request even when asked twice.
	 */
	public function test_depth_is_memoized_per_request(): void {
		$depth = new SeriesDepth( new Settings() );

		self::assertSame( 17, $depth->sellable_count( self::TERM_ID ) );
		self::assertSame( 17, $depth->sellable_count( self::TERM_ID ) );
		self::assertCount( 1, $this->wpdb->queries );
	}

	// -------------------------------------------------------------------------
	// Landing SIN descuento aplicable
	// -------------------------------------------------------------------------

	/**
	 * Un tramo `{min:2, pct:0}` es config válida (el guard anti-canibalización
	 * obliga a que el DESCUENTO empiece en 3, no a que todo tramo descuente). En
	 * la ficha un "0% dto." es ruido; acá sería un titular que promete un ahorro
	 * inexistente.
	 *
	 * @testdox Should keep multi-buy while suppressing a zero-percent discount claim.
	 */
	public function test_zero_percent_tier_is_not_an_offer(): void {
		$this->set_tiers(
			array(
				array(
					'min' => 2,
					'pct' => 0,
				),
			)
		);

		$html = $this->render();
		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( 'Elegir tomos', $html, 'La compra múltiple sigue.' );
		self::assertStringNotContainsString( '<details', $html, 'Sin descuento no hay condiciones que desplegar.' );
		self::assertStringNotContainsString( '0% dto.', $html );
		self::assertStringNotContainsString( 'obtén hasta', $html );
	}

	/**
	 * Revisión B3-2-R4: sin descuento, la frase «Selecciona varios tomos y
	 * agrégalos juntos al carrito en un solo paso» eran dos líneas (36 px en
	 * 390x844) entre la cabecera y la primera portada, y repetía lo que dicen el
	 * botón «Elegir tomos» y las instrucciones del panel. Con un nombre de serie
	 * largo, el riel medía 167 px y la portada quedaba bajo la barra inferior.
	 *
	 * @testdox Without a discount the rail head is the title, the chips and the button, with no intro paragraph.
	 */
	public function test_rail_without_discount_has_no_intro_paragraph(): void {
		$this->options['akb_series_bundles_byp'] = '{}';

		$html = $this->render();
		preg_match( '#<div class="akb-sb-landing__copy">(.*?)</div>\s*<form#s', $html, $copy );

		self::assertNotEmpty( $copy, 'La cabecera del riel sigue ahí.' );
		self::assertStringContainsString( 'akb-sb-landing__title', $copy[1] );
		self::assertStringNotContainsString( '<p', $copy[1], 'Ni frase de intro ni ningún otro párrafo bajo el título.' );
		self::assertStringNotContainsString( 'agrégalos juntos', $html );
		self::assertStringContainsString( 'data-akb-sb-landing-start', $html, 'El botón «Elegir tomos» dice qué hacer.' );
		self::assertStringContainsString( 'Toca una tarjeta', $html, 'Y el panel que abre lo explica.' );

		// El nombre de la serie va en su span: en móvil sólo lo lee el lector de
		// pantalla (con un nombre largo partía el título en dos líneas), y el
		// nombre accesible de la sección sigue completo.
		self::assertMatchesRegularExpression(
			'#<h2 class="akb-sb-landing__title" id="([^"]+)">Completa tu colección<span class="akb-sb-landing__title-serie"> de [^<]+</span></h2>#',
			$copy[1]
		);

		// El título va solo: en escritorio se centra en la fila (sólo sin descuento;
		// con él, abrir «Cómo funciona» bajaría chips y botón — revisión B3-R7).
		self::assertStringContainsString( 'class="akb-sb-landing akb-sb-landing--sin-descuento"', $html );
	}

	/**
	 * @testdox Should render multi-buy without discount copy when the series has no BYP config.
	 */
	public function test_series_without_byp_config_prints_nothing(): void {
		$this->options['akb_series_bundles_byp'] = '{}';

		$html = $this->render();
		self::assertStringContainsString( 'data-tiers="[]"', $html );
		self::assertStringContainsString( 'Agregar seleccionados', $html );
		self::assertStringNotContainsString( '% de descuento', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/producto-22201/"', $html );
		self::assertStringContainsString( 'La selección múltiple necesita JavaScript.', $html );
	}

	/**
	 * El interruptor apaga TODA la superficie, no sólo el descuento.
	 *
	 * Decisión del dueño, 2026-09-06, tomada con los datos de su tienda delante:
	 * 42 de 84 pedidos llevan dos o más artículos y 12 han usado el cupón de
	 * pack, así que lo que atrae es el descuento. El estado que había —elegir
	 * varios tomos, agregarlos juntos y no recibir nada— era el peor de los tres
	 * posibles: el cliente hacía todo el trabajo del pack sin la recompensa.
	 *
	 * Es además lo que promete la descripción del propio panel («Al desactivarlo,
	 * se oculta el selector de packs») y lo que ya hacía la ficha de producto
	 * (`BuilderWidget::should_render_for()`), que sí cortaba en seco. La landing
	 * era la única superficie con la otra semántica.
	 *
	 * @testdox Con el módulo apagado, la landing no pinta nada del pack.
	 */
	public function test_disabled_module_prints_nothing(): void {
		$this->options['akb_series_bundles_enabled'] = 'no';

		$html = $this->render();

		self::assertSame( '', $html, 'Apagado es apagado: ni riel, ni selector, ni «Agregar seleccionados».' );
	}

	/**
	 * @testdox Apagado tampoco se encolan el CSS ni los 49 KB de JS del pack.
	 */
	public function test_disabled_module_enqueues_nothing(): void {
		$this->options['akb_series_bundles_enabled'] = 'no';

		$encolados = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( string $handle ) use ( &$encolados ): void {
				$encolados[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle ) use ( &$encolados ): void {
				$encolados[] = $handle;
			}
		);

		$this->offer()->enqueue_assets();

		self::assertSame(
			array(),
			$encolados,
			'Cargar 49 KB de JS en cada página de serie para una función apagada es peso que paga el cliente.'
		);
	}

	/**
	 * Un fallo sin respuesta que leer (se cortó la red, un 500) lo explica el
	 * script con `i18n.error`. En «Elegir tomos» era un literal fijo, distinto
	 * del de «Arma tu pack» en la ficha, y el campo «Packs: no se pudo agregar»
	 * de Akibara → Textos no lo alcanzaba (REV-ZN-1).
	 *
	 * @testdox El fallo de red de «Elegir tomos» dice el texto del dueño, el mismo de la ficha.
	 */
	public function test_network_failure_says_the_owners_text(): void {
		$datos = array();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_script_add_data' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://akibara.cl/carrito/' );
		Functions\when( 'wp_localize_script' )->alias(
			static function ( string $handle, string $name, array $data ) use ( &$datos ): bool {
				$datos[ $name ] = $data;
				return true;
			}
		);

		$this->offer()->enqueue_assets();
		self::assertSame( SelectionCart::DEFAULT_ERROR, $datos['akbSbLanding']['i18n']['error'] ?? null );

		$this->options[ SelectionCart::OPTION_ERROR ] = "Se cortó la conexión.\nVuelve a intentarlo.";
		$this->offer()->enqueue_assets();
		self::assertSame(
			'Se cortó la conexión. Vuelve a intentarlo.',
			$datos['akbSbLanding']['i18n']['error'] ?? null,
			'Lo que el dueño guarda en «Packs: no se pudo agregar» llega también a la página de la serie.'
		);
	}

	/**
	 * Con 4 tomos vendibles y tramos {3:0%, 6:10%}, el ÚNICO tramo con descuento
	 * es inalcanzable: publicar "desde 6 tomos" en una serie que sólo puede vender
	 * 4 es una promesa que el carrito nunca cumple.
	 *
	 * @testdox Should drop tiers the catalogue cannot reach, tier by tier.
	 */
	public function test_unreachable_discounted_tier_is_not_published(): void {
		$this->set_series_volumes( 4 );
		$this->set_tiers(
			array(
				array(
					'min' => 3,
					'pct' => 0,
				),
				array(
					'min' => 6,
					'pct' => 10,
				),
			)
		);

		$html = $this->render();
		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringNotContainsString( 'Desde 6 tomos · 10% dto.', $html );
	}

	/**
	 * Sin `$wpdb` no hay forma de saber si el umbral es alcanzable. La ficha falla
	 * al revés (asume que sí) porque `build_html()` vuelve a chequear en vivo; acá
	 * no hay segundo chequeo y el bloque afirma un precio en una página cacheable.
	 *
	 * @testdox Should fail closed on discount copy while retaining ordinary multi-buy.
	 */
	public function test_missing_count_fails_closed(): void {
		$this->wpdb->ids = null;

		$html = $this->render();
		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringNotContainsString( '% dto.', $html );
	}

	/**
	 * El conteo con el que se filtran los tramos tiene que ser el universo que el
	 * CARRITO acepta, no el que `SeriesDepth::sellable_count()` cuenta —que por su
	 * propio docblock «cuenta de más a propósito»—. Caso medido: 6 tomos instock,
	 * tramos {3:5%, 6:10%} y UNO de los seis en una categoría excluida
	 * (`akb_sb_exclude_cats`, cuyo ejemplo canónico es «Novedades, Preventas»).
	 * `CartSeriesCollector::collect()` lo descarta, así que el tope real es 5 y el
	 * 10% no se puede otorgar jamás: publicarlo sería anunciar un descuento
	 * inalcanzable por construcción en una página indexada.
	 *
	 * @testdox Should drop a tier that only the excluded-category volumes would reach.
	 */
	public function test_excluded_category_volumes_do_not_count_towards_a_tier(): void {
		$this->set_series_volumes( 6 );
		$this->set_tiers(
			array(
				array(
					'min' => 3,
					'pct' => 5,
				),
				array(
					'min' => 6,
					'pct' => 10,
				),
			)
		);

		$novedades                        = new \WP_Term( 991, 'novedades', 'Novedades' );
		$this->product_cats[ 22206 ]      = array( $novedades );
		$this->options['akb_series_bundles_exclude_cats'] = array( 991 );

		$html = $this->render();

		self::assertStringContainsString( 'Desde 3 tomos · 5% dto.', $html );
		self::assertStringNotContainsString(
			'Desde 6 tomos · 10% dto.',
			$html,
			'El carrito nunca puede juntar 6 tomos elegibles de esta serie.'
		);
	}

	/**
	 * El mismo guard por el otro lado: sin categorías excluidas los seis cuentan y
	 * el tramo alto sí es alcanzable. Sin este par, un conteo roto que devolviera
	 * siempre de menos pasaría la prueba de arriba.
	 *
	 * @testdox Should keep the top tier when every volume is eligible.
	 */
	public function test_top_tier_survives_when_no_volume_is_excluded(): void {
		$this->set_series_volumes( 6 );
		$this->set_tiers(
			array(
				array(
					'min' => 3,
					'pct' => 5,
				),
				array(
					'min' => 6,
					'pct' => 10,
				),
			)
		);

		self::assertStringContainsString( 'Desde 6 tomos · 10% dto.', $this->render() );
	}

	// -------------------------------------------------------------------------
	// Serie de un solo tomo (8 de 104 lo son)
	// -------------------------------------------------------------------------

	/**
	 * @testdox Should still expose filters and the ordinary CTA for a one-volume catalogue.
	 */
	public function test_single_volume_series_prints_nothing(): void {
		$this->set_series_volumes( 1 );

		$html = $this->render();
		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( 'aria-label="1 productos"', $html );
	}

	/**
	 * Sin UN SOLO tomo que el carrito acepte no hay pack posible, y el bloque calla
	 * — pero por el universo de la SERIE, no por lo que la página muestre.
	 *
	 * @testdox Should keep catalogue filters when every visible volume is unavailable.
	 */
	public function test_series_without_eligible_volumes_prints_nothing(): void {
		$this->set_series_volumes( 17 );
		foreach ( array_keys( $this->products ) as $id ) {
			$this->products[ $id ] = new LandingOfferProduct( $id, 'The Climber', 'outofstock', false );
		}

		$html = $this->render();
		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( '>Agotados</span>', $html );
		self::assertStringNotContainsString( '% dto.', $html );
	}

	/**
	 * El enlace es lo ÚNICO que necesita un producto concreto, así que es lo único
	 * que se cae cuando no hay ninguno resoluble. El enunciado del umbral sigue
	 * siendo cierto sin enlace y las cards de arriba siguen armando el pack:
	 * borrar el bloque entero por un `href` que no resuelve es tirar el mensaje por
	 * su adorno. (Antes se borraba, y como la puerta se buscaba en el loop, eso
	 * hacía que el bloque apareciera o no según la página.)
	 *
	 * @testdox Should keep a useful non-fragment fallback when no PDP permalink resolves.
	 */
	public function test_block_survives_without_a_resolvable_entry_link(): void {
		Functions\when( 'get_permalink' )->justReturn( false );

		$html = $this->render();

		self::assertStringContainsString( 'akb-sb-landing', $html );
		self::assertStringContainsString( 'Desde 3 tomos · 10% dto.', $html );
		self::assertStringContainsString( 'Se aplica solo al sumarlos al carrito.', $html );
		self::assertStringContainsString( 'akb-sb-landing__cta', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/serie/the-climber/"', $html );
		self::assertStringContainsString( '<noscript>', $html );
		self::assertStringContainsString( 'abrir una ficha para comprar', $html );
		self::assertStringNotContainsString( '#akb-sb-builder-title-', $html );
		self::assertStringNotContainsString( 'href="https://akibara.cl/serie/the-climber/#completar-coleccion"', $html );
	}

	// -------------------------------------------------------------------------
	// Preventa: lo que el tramo NO cuenta
	// -------------------------------------------------------------------------

	/**
	 * `akb_sb_allow_backorder` viene en 'no' por defecto y con ese valor
	 * `CartSeriesCollector::collect()` DESCARTA los tomos en preventa. La rejilla
	 * de la misma página los muestra igual, con «Reservar en Preventa» a un tap (5
	 * de los 17 de The Climber), así que sin el calificador el bloque promete un
	 * descuento por una acción que el motor no premia: el lector suma tres
	 * preventas, el carrito las cobra completas y nada lo explica. La PDP ya
	 * resolvió esto por dos vías; faltaba decirlo donde se elige sin entrar a
	 * ninguna ficha.
	 *
	 * @testdox Should say preorders do not count while the backorder switch is off.
	 */
	public function test_states_that_preorders_do_not_count_when_the_switch_is_off(): void {
		self::assertStringContainsString(
			'Cuentan los tomos disponibles ahora; los a pedido no.',
			$this->render()
		);
	}

	/**
	 * Con el switch en 'yes' la preventa SÍ cuenta para el tramo y para el cupón,
	 * así que el calificador sería falso. Es la mitad que impide "arreglarlo"
	 * imprimiendo la frase siempre.
	 *
	 * @testdox Should drop the qualifier when preorders do count.
	 */
	public function test_omits_the_qualifier_when_preorders_count(): void {
		$this->options['akb_series_bundles_allow_backorder'] = 'yes';

		self::assertStringNotContainsString( 'los a pedido no', $this->render() );
	}

	// -------------------------------------------------------------------------
	// Fuera de la landing
	// -------------------------------------------------------------------------

	/**
	 * @testdox Should print nothing outside a pa_serie archive.
	 */
	public function test_other_archives_print_nothing(): void {
		Functions\when( 'is_tax' )->justReturn( false );

		self::assertSame( '', $this->render() );
	}

	/**
	 * @testdox Should print nothing in the admin.
	 */
	public function test_admin_prints_nothing(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		self::assertSame( '', $this->render() );
	}

	// -------------------------------------------------------------------------
	// Registro y contrato de caché
	// -------------------------------------------------------------------------

	/**
	 * ENCIMA de la grilla, después del toolbar y sus facetas.
	 *
	 * El bloque son 3–4 líneas con el copy real en 375 px (~90 px) y arriba las
	 * gastaba justo donde el informe de UX pedía recuperar alto: la primera portada
	 * arranca en 771 px sobre un viewport útil de ~700 px, y el clamp de la
	 * descripción acababa de devolver ~51 px. Abajo, la prioridad 5 cae ANTES de
	 * `woocommerce_pagination` (prio 10 del mismo hook), así que el bloque queda
	 * pegado al final de la grilla y sobre la paginación — donde el lector ya sabe
	 * cuántos tomos quiere.
	 *
	 * @testdox Should hook after the toolbar and before the product grid.
	 */
	public function test_registers_below_the_grid_above_the_pagination(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$actions ): void {
				$actions[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		$offer = $this->offer();
		$offer->register();

		self::assertContains(
			array(
				'hook'     => 'woocommerce_before_shop_loop',
				'callback' => array( $offer, 'render' ),
				'priority' => 40,
			),
			$actions
		);

		foreach ( $actions as $action ) {
			if ( 'woocommerce_before_shop_loop' !== $action['hook'] ) {
				continue;
			}
			self::assertGreaterThan(
				35,
				$action['priority'],
				'Debe salir después del toolbar, que cierra en prioridad 35.'
			);
		}

		foreach ( $actions as $action ) {
			self::assertNotSame(
				'woocommerce_after_single_product_summary',
				$action['hook'],
				'La landing no puede tocar la ficha: ése es el BuilderWidget.'
			);
		}
	}

	/**
	 * El bloque vive en 106 URLs indexadas y cacheables. Si leyera carrito o
	 * usuario, hornearía el estado de un visitante para todos los demás — que es
	 * exactamente por lo que NO se reusó `BuilderWidget::build_html()` (su
	 * `data-free-ship` sale de `FreeShippingThreshold::for_current_cart()`).
	 *
	 * @testdox Should never bake cart or user state into a cacheable landing.
	 */
	public function test_landing_html_is_cache_safe(): void {
		// Sin comentarios: el docblock de la clase NOMBRA a propósito las cuatro
		// cosas prohibidas (explica por qué no se reusó `build_html()`), así que
		// leer el archivo crudo haría fallar el assert por la explicación en vez
		// de por el código. `php_strip_whitespace` deja sólo lo que se ejecuta.
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/LandingOffer.php'
		);
		$start = strpos( $source, 'private function build_html()' );
		$end   = strpos( $source, 'private function catalog_counts(', false === $start ? 0 : $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$code = substr( $source, (int) $start, (int) $end - (int) $start );

		self::assertStringNotContainsString( 'WC()->cart', $code );
		self::assertStringNotContainsString( 'get_current_user_id', $code );
		self::assertStringNotContainsString( 'FreeShippingThreshold', $code );
		self::assertStringNotContainsString( 'CustomerCollection', $code );
	}

	/**
	 * Las clases del bloque son condicionales por partida doble (sólo en series
	 * con BYP, y sólo cuando el tramo es alcanzable): si el muestreo del UCSS de
	 * LiteSpeed cae en una landing sin pack —lo más probable— poda las reglas para
	 * todas las demás.
	 *
	 * @testdox Should protect its conditional classes from LiteSpeed UCSS pruning.
	 */
	public function test_allowlists_its_conditional_classes(): void {
		$selectors = $this->offer()->allowlist_ucss_selectors( array( '.ya-estaba' ) );

		self::assertContains( '.ya-estaba', $selectors );
		self::assertContains( '.akb-sb-landing', $selectors );
		self::assertContains( '.akb-sb-landing__tier', $selectors );
		self::assertContains( '.akb-sb-landing__cta', $selectors );
	}

	/** @testdox Assets are gated by catalogue presence, never by BYP configuration. */
	public function test_asset_gate_covers_series_without_byp(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/LandingOffer.php'
		);
		$start = strpos( $source, 'public function enqueue_assets()' );
		$end   = strpos( $source, 'public function render()', false === $start ? 0 : $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'catalog_counts( $term )', $method );
		self::assertStringNotContainsString( 'resolve_offer(', $method );
		self::assertStringNotContainsString( 'config_for_term(', $method );
		self::assertStringContainsString( "'addAction'        => self::ACTION_ADD_SERIES", $method );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function offer(): LandingOffer {
		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );

		return new LandingOffer(
			$settings,
			$guard,
			new VolumeTierResolver(),
			$collector,
			new SeriesDepth( $settings ),
			function ( int $term_taxonomy_id ): array {
				self::assertSame( self::TERM_ID, $term_taxonomy_id );
				$counts = array(
					'tomos'    => count( $this->products ),
					'instock'  => 0,
					'preventa' => 0,
					'agotados' => 0,
				);
				foreach ( $this->products as $product ) {
					$status = $product->get_stock_status();
					if ( 'instock' === $status ) {
						++$counts['instock'];
					} elseif ( 'onbackorder' === $status ) {
						++$counts['preventa'];
					} else {
						++$counts['agotados'];
					}
				}

				return $counts;
			}
		);
	}

	private function render(): string {
		ob_start();
		$this->offer()->render();

		return trim( (string) ob_get_clean() );
	}

	/** Término de serie consultado. */
	private function series_term(): \WP_Term {
		$term           = new \WP_Term( self::TERM_ID, 'the-climber', 'The Climber' );
		$term->taxonomy = 'pa_serie';

		return $term;
	}

	/**
	 * Reescribe los tramos persistidos de la serie.
	 *
	 * @param array<int, array{min:int, pct:int}> $tiers
	 */
	private function set_tiers( array $tiers ): void {
		$this->options['akb_series_bundles_byp'] = (string) json_encode(
			array(
				(string) self::TERM_ID => array(
					'enabled' => true,
					'tiers'   => $tiers,
				),
			)
		);
	}

	/**
	 * Puebla la SERIE con `$total` tomos vendibles, del 01 al N.
	 *
	 * Los ids son los que devuelve `SeriesDepth::sellable_ids()` y los productos
	 * los que hidrata el recorrido: las dos mitades del universo term-driven.
	 *
	 * @param int $total Cuántos tomos publicados y vendibles tiene la serie.
	 */
	private function set_series_volumes( int $total ): void {
		$this->products = array();
		$ids            = array();

		for ( $volume = 1; $volume <= $total; $volume++ ) {
			$id                    = 22200 + $volume;
			$ids[]                 = $id;
			$this->products[ $id ] = new LandingOfferProduct(
				$id,
				sprintf( 'The Climber %02d', $volume )
			);
		}

		$this->wpdb->ids   = $ids;
		$this->wpdb->count = $total;
	}

	/**
	 * Puebla el loop principal con los productos que la grilla ya trajo.
	 *
	 * @param array<int, int> $ids
	 */
	private function set_loop( array $ids ): void {
		$posts = array();
		foreach ( $ids as $id ) {
			$post     = new \stdClass();
			$post->ID = $id;
			$posts[]  = $post;
		}

		$query               = new \stdClass();
		$query->posts        = $posts;
		$GLOBALS['wp_query'] = $query;
	}
}
