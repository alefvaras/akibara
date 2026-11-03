<?php
/**
 * Candado de «Novedades primero» en las landings de serie (B1-R1, 2026-09-22).
 *
 * `menu_order` es el número de la VITRINA. Con la vitrina «disponibles primero»
 * dejó de significar «lo más nuevo arriba» dentro de una serie, y la landing
 * que lo ofrecía con esa etiqueta empezó a mentir: Haikyuu abría con 44 → 36 y
 * 07 → 01 antes de la novedad «a pedido». Estas pruebas fijan que en `pa_serie`
 * esa opción se traduce a fecha DESC con lo agotado al final, y que en ninguna
 * otra superficie se toca nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Akibara\Business\Modules\CatalogOrder\SeriesNewestOrder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Query;

final class SeriesNewestOrderTest extends TestCase {

	/** Lo que WooCommerce arma para `menu_order` antes de pasar por el filtro. */
	private const WC_MENU_ORDER_ARGS = array(
		'orderby'  => 'menu_order title',
		'order'    => 'ASC',
		'meta_key' => '',
	);

	/** La cláusula que WordPress arma para `date ID` DESC. */
	private const WP_DATE_CLAUSE = 'wp_posts.post_date DESC, wp_posts.ID DESC';

	/** @var mixed */
	private $wpdb_original;

	private bool $en_serie = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_original = $GLOBALS['wpdb'] ?? null;
		// `prepare()` con la semántica de `%s` de wpdb: comillas simples y el
		// valor escapado. El estado agotado ya no va escrito en el SQL.
		$GLOBALS['wpdb'] = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			/**
			 * @param string $sql  Consulta con marcadores.
			 * @param mixed  ...$args Valores.
			 */
			public function prepare( string $sql, ...$args ): string {
				foreach ( $args as $arg ) {
					$sql = (string) preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $sql, 1 );
				}
				return $sql;
			}
		};

		$this->en_serie = true;
		Functions\when( 'is_tax' )->alias(
			fn( $taxonomy = '' ): bool => $this->en_serie && 'pa_serie' === $taxonomy
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_original;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Consulta principal tal como la deja WooCommerce con los args traducidos.
	 */
	private static function consulta( string $orderby = 'date ID', bool $principal = true ): WP_Query {
		$query                       = new WP_Query( array( 'orderby' => $orderby, 'order' => 'DESC' ) );
		$query->is_main_query_result = $principal;

		return $query;
	}

	/**
	 * @testdox En una landing de serie, «Novedades primero» ordena por fecha DESC y no por la vitrina
	 */
	public function test_menu_order_in_a_series_archive_becomes_newest_first(): void {
		$sut = new SeriesNewestOrder();

		$args = $sut->translate_ordering_args( self::WC_MENU_ORDER_ARGS, 'menu_order' );

		self::assertSame( 'date ID', $args['orderby'], 'menu_order es el número de la vitrina: aquí no dice «lo más nuevo».' );
		self::assertSame( 'DESC', $args['order'] );
		self::assertSame( '', $args['meta_key'] );
		self::assertSame( 10, has_filter( 'posts_orderby', array( $sut, 'sold_out_last' ) ), 'Lo agotado al final se añade a la cláusula ORDER BY.' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function otrosOrdenes(): array {
		return array(
			'Tomo 1 → N'    => array( 'title' ),
			'Más recientes' => array( 'date' ),
			'Menor precio'  => array( 'price' ),
			'Más vendidos'  => array( 'popularity' ),
			'relevancia'    => array( 'relevance' ),
		);
	}

	/**
	 * @testdox Los demás órdenes de la landing no se tocan
	 * @dataProvider otrosOrdenes
	 *
	 * @param string $orderby Clave de orden resuelta por WooCommerce.
	 */
	public function test_other_orders_are_left_alone( string $orderby ): void {
		$sut  = new SeriesNewestOrder();
		$args = array(
			'orderby'  => 'algo',
			'order'    => 'ASC',
			'meta_key' => '',
		);

		self::assertSame( $args, $sut->translate_ordering_args( $args, $orderby ) );
		self::assertFalse( has_filter( 'posts_orderby', array( $sut, 'sold_out_last' ) ) );
	}

	/**
	 * @testdox Fuera de las landings de serie «Destacados» sigue siendo la vitrina
	 */
	public function test_the_showcase_keeps_menu_order_outside_series_archives(): void {
		$this->en_serie = false;
		$sut            = new SeriesNewestOrder();

		self::assertSame( self::WC_MENU_ORDER_ARGS, $sut->translate_ordering_args( self::WC_MENU_ORDER_ARGS, 'menu_order' ) );
		self::assertFalse( has_filter( 'posts_orderby', array( $sut, 'sold_out_last' ) ) );
	}

	/**
	 * @testdox Un valor raro en los args no rompe la consulta
	 */
	public function test_unexpected_args_pass_through(): void {
		self::assertSame( 'no-array', ( new SeriesNewestOrder() )->translate_ordering_args( 'no-array', 'menu_order' ) );
	}

	/**
	 * @testdox Lo agotado va al final y, dentro de cada grupo, lo más nuevo arriba
	 */
	public function test_sold_out_goes_last_then_newest_first(): void {
		$sut = new SeriesNewestOrder();
		$sut->translate_ordering_args( self::WC_MENU_ORDER_ARGS, 'menu_order' );

		$clausula = $sut->sold_out_last( self::WP_DATE_CLAUSE, self::consulta() );

		self::assertMatchesRegularExpression(
			"/^EXISTS \\( SELECT 1 FROM wp_postmeta akb_agotado WHERE akb_agotado\\.post_id = wp_posts\\.ID AND akb_agotado\\.meta_key = '_stock_status' AND akb_agotado\\.meta_value = 'outofstock' \\) ASC, /",
			$clausula,
			'Primero el criterio «agotado», ascendente: los vendibles (0) antes que los agotados (1).'
		);
		self::assertStringEndsWith( ', ' . self::WP_DATE_CLAUSE, $clausula, 'Después, el orden por fecha que armó WordPress, intacto.' );
		self::assertFalse( has_filter( 'posts_orderby', array( $sut, 'sold_out_last' ) ), 'Se aplica a UNA consulta y se retira.' );
	}

	/**
	 * @testdox La cláusula no toca otras consultas de la página
	 */
	public function test_secondary_queries_are_not_touched(): void {
		$sut = new SeriesNewestOrder();
		$sut->translate_ordering_args( self::WC_MENU_ORDER_ARGS, 'menu_order' );

		self::assertSame( self::WP_DATE_CLAUSE, $sut->sold_out_last( self::WP_DATE_CLAUSE, self::consulta( 'date ID', false ) ) );
		self::assertSame( 10, has_filter( 'posts_orderby', array( $sut, 'sold_out_last' ) ), 'Sigue esperando a la consulta principal.' );
	}

	/**
	 * @testdox Si el buscador ordena por relevancia, la cláusula no la pisa
	 */
	public function test_search_relevance_wins(): void {
		$sut = new SeriesNewestOrder();
		$sut->translate_ordering_args( self::WC_MENU_ORDER_ARGS, 'menu_order' );

		$relevancia = 'FIELD( wp_posts.ID, 3, 1, 2 )';

		self::assertSame( $relevancia, $sut->sold_out_last( $relevancia, self::consulta( 'post__in' ) ) );
	}

	/**
	 * @testdox El módulo de la vitrina es quien la registra
	 */
	public function test_the_catalog_order_module_registers_it(): void {
		( new CatalogOrder() )->register();

		self::assertSame(
			20,
			has_filter( 'woocommerce_get_catalog_ordering_args', SeriesNewestOrder::class . '->translate_ordering_args()' ),
			'Prioridad 20: después de que akibara-business.php resuelva `title`.'
		);
	}
}
