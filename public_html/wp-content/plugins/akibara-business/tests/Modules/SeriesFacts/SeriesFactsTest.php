<?php
/**
 * Pruebas de la ficha de datos de las landings `pa_serie`.
 *
 * ── Por qué setUp() declara TODOS los guards de contexto ─────────────────────
 * Brain Monkey define la función en el runtime de PHP la primera vez que un test
 * la mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`: lo que se limpia
 * es la expectativa, no el símbolo. A partir de ahí, un test que llame a
 * `is_tax()` sin stubearla no recibe el comportamiento nativo — recibe el stub
 * vacío, que devuelve null. Traducido a esta suite: `queried_series_tt_id()`
 * daría 0 y TODOS los asserts de render fallarían… pero sólo en las
 * permutaciones donde otro test corre antes. Verde en orden natural, rojo con
 * `--order-by=random`, que es la peor clase de verde.
 *
 * Por eso acá se fijan defaults DETERMINISTAS de todo lo que los guards tocan,
 * aunque el test concreto no lo use (misma disciplina que DisponiblesRouteTest).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFacts;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Doble de `$wpdb` que devuelve UNA fila agregada y cuenta las consultas.
 *
 * El contador no es decorativo: el contrato de cacheo ("una consulta por
 * término cada 6 h, jamás N+1") sólo se puede observar contando, y un doble sin
 * contador dejaría pasar tanto un transient que nunca se escribe como una
 * consulta por producto.
 */
class SeriesFactsWpdb {

	public string $prefix              = 'wp_';
	public string $posts               = 'wp_posts';
	public string $terms               = 'wp_terms';
	public string $term_taxonomy       = 'wp_term_taxonomy';
	public string $term_relationships  = 'wp_term_relationships';
	public string $postmeta             = 'wp_postmeta';
	public string $options              = 'wp_options';

	/** @var array<string,mixed> Almacén de opciones compartido con el test. */
	public array $option_store = array();

	/** Consultas ejecutadas (SQL ya interpolado). */
	public array $queries = array();

	/** Lecturas del snapshot de generación (`get_results`). */
	public array $generation_queries = array();

	/** Fila que devuelve la agregación; null = error de base de datos. */
	public ?array $row = null;

	/** `term_taxonomy_id` de las series que `get_col()` atribuye a un producto. */
	public array $col = array();
	public int $num_queries = 0;
	public string $last_error = '';
	public bool $ready = true;
	public bool $fail_col = false;
	/** Simula una caída al leer el snapshot de generación. */
	public bool $fail_results = false;

	/**
	 * @param string $query Consulta preparada.
	 * @return array<int, string>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		++$this->num_queries;
		if ( $this->fail_col ) {
			$this->last_error = 'temporary database failure';
			return array();
		}
		$this->last_error = '';

		return $this->col;
	}

	/**
	 * Lectura del snapshot de generación, igual que en producción.
	 *
	 * El doble NO tenía este método, así que `method_exists()` fallaba y todos
	 * los tests caían a la rama de respaldo por `get_option()`. Es decir: la
	 * suite validaba un camino que sólo existe para dobles, y el que corre en el
	 * sitio no tenía ni una prueba.
	 *
	 * @param string $query  Consulta preparada.
	 * @param string $output Constante de formato (ARRAY_A).
	 * @return array<int, array<string,mixed>>|null
	 */
	public function get_results( string $query, string $output = 'OBJECT' ) {
		unset( $output );
		// Bitácora aparte: `$queries` la inspeccionan los tests que cuentan
		// lotes de invalidación, y el snapshot de generación no es uno de ellos.
		$this->generation_queries[] = $query;
		++$this->num_queries;

		// `wpdb::query()` limpia `last_error` al empezar cada consulta. Sin esto
		// el doble arrastraba el error de un `get_col()` anterior y fingía una
		// caída de base de datos que en el sitio real no ocurre.
		$this->last_error = $this->fail_results ? 'temporary database failure' : '';

		if ( ! $this->ready || $this->fail_results ) {
			return null;
		}

		if ( ! preg_match_all( "/'([^']+)'/", $query, $matches ) ) {
			return array();
		}

		$rows = array();
		foreach ( $matches[1] as $name ) {
			if ( ! array_key_exists( $name, $this->option_store ) ) {
				continue;
			}
			$rows[] = array(
				'option_name'  => $name,
				'option_value' => $this->option_store[ $name ],
			);
		}

		return $rows;
	}

	/**
	 * @param string $query Consulta preparada.
	 * @param string $output Constante de formato (ARRAY_A).
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output = 'OBJECT' ) {
		unset( $output );
		$this->queries[] = $query;

		return $this->row;
	}

	/**
	 * Imita el quoting real de WordPress: lo que se prueba es qué literales
	 * viajan al SQL (los tres `stock_status`, la taxonomía de editorial y el
	 * término), así que un doble que devolviera el formato sin sustituir
	 * escondería exactamente el defecto que importa.
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

	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where ): int {
		unset( $table );
		$name = (string) ( $where['option_name'] ?? '' );
		if ( '' === $name || ! array_key_exists( $name, $this->option_store ) ) {
			return 0;
		}
		$this->option_store[ $name ] = $data['option_value'] ?? '';
		return 1;
	}

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data ): int {
		unset( $table );
		$name = (string) ( $data['option_name'] ?? '' );
		if ( '' === $name || array_key_exists( $name, $this->option_store ) ) {
			return 0;
		}
		$this->option_store[ $name ] = $data['option_value'] ?? '';
		return 1;
	}
}

/** Doble realista: wpdb devuelve [] y deja el fallo sólo en last_error. */
final class SeriesFactsGenerationReadFailureWpdb extends SeriesFactsWpdb {

	/** @return array<int,array<string,string>> */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $output );
		$this->queries[] = $query;
		++$this->num_queries;
		$this->last_error = 'generation lookup failed';
		return array();
	}
}

/**
 * Doble donde otra petición rota la generación de la serie entre CADA lectura:
 * ninguna lectura puede quedar verificada, ni la del transient ni la que lo llena.
 */
final class SeriesFactsRotatingGenerationWpdb extends SeriesFactsWpdb {

	/** Lecturas del snapshot hechas hasta ahora. */
	public int $reads = 0;

	/** @return array<int, array<string,mixed>>|null */
	public function get_results( string $query, string $output = 'OBJECT' ) {
		++$this->reads;
		$this->option_store['akb_series_facts_generation_378'] = 'gen-' . $this->reads;

		return parent::get_results( $query, $output );
	}
}

final class SeriesFactsTest extends TestCase {

	/** @var list<string> Claves del object cache evictadas durante el test. */
	private array $cache_deletes = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var mixed */
	private $original_wpdb = null;

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var array<string,int> */
	private array $query_vars = array();

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<string,mixed> Caché local de options del request simulado. */
	private array $option_cache = array();

	private bool $option_updates_fail = false;

	/** @var list<string> */
	private array $generation_tokens = array();

	private SeriesFactsWpdb $wpdb;

	/** Fila "One Piece" verificada contra el catálogo real. */
	private const ONE_PIECE = array(
		'tomos'            => '107',
		'instock'          => '31',
		'preventa'         => '76',
		'agotados'         => '0',
		// Sin agotados, el `GROUP_CONCAT` de títulos no tiene nada que concatenar
		// y MySQL devuelve NULL. Va explícito en la fila de referencia para que se
		// vea que el caso "no hay lista" es el normal, no una omisión del doble.
		'agotados_titulos' => null,
		'price_min'        => '9500.0000',
		'price_max'        => '9990.0000',
		'updated'          => '2026-08-04 11:42:07',
		// Pares `nombre<US>term_id` separados por <RS>, tal como los devuelve el
		// `GROUP_CONCAT`. El elemento vacío de delante NO es ruido del doble: los
		// productos sin el término aportan un `CONCAT_WS` vacío (no NULL) y ese
		// elemento existe de verdad en las filas reales, verificadas contra la
		// base local (One Piece → Eiichiro Oda, term 244; Ivrea Argentina, 230).
		'autores'          => "\x1e" . "Eiichiro Oda\x1f244",
		'editoriales'      => "\x1e" . "Ivrea Argentina\x1f230",
		// `MAX(título<US>ID)` de los tomos normales: el último de la rejilla en
		// «Tomo 1 → N». Id de la ficha de One Piece 107 en el espejo.
		'ultimo_tomo'      => "One Piece 107\x1f5107",
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->cache_deletes = array();

		$this->original_get  = $_GET;
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$_GET                = array();
		$this->transients    = array();
		$this->query_vars    = array( 'paged' => 1 );
		$this->options       = array();
		$this->option_cache  = array();
		$this->option_updates_fail = false;
		$this->generation_tokens   = array();

		$this->wpdb      = new SeriesFactsWpdb();
		$this->wpdb->row = self::ONE_PIECE;
		$this->wpdb->option_store =& $this->options;
		$GLOBALS['wpdb'] = $this->wpdb;

		// Guards de contexto. Defaults deterministas: el archive limpio de una
		// serie, que es el único request donde el bloque debe salir.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->series_term( 378 ) );
		Functions\when( 'get_query_var' )->alias(
			fn( string $key ): int => $this->query_vars[ $key ] ?? 0
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				if ( array_key_exists( $key, $this->option_cache ) ) {
					return $this->option_cache[ $key ];
				}
				if ( ! array_key_exists( $key, $this->options ) ) {
					return $default;
				}
				$this->option_cache[ $key ] = $this->options[ $key ];
				return $this->option_cache[ $key ];
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				if ( $this->option_updates_fail ) {
					return false;
				}
				$this->options[ $key ] = $value;
				$this->option_cache[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->alias(
			function (): string {
				return array() === $this->generation_tokens
					? 'test-generation'
					: (string) array_shift( $this->generation_tokens );
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key, string $group = '' ): bool {
				if ( 'options' === $group ) {
					$this->cache_deletes[] = $key;
					unset( $this->option_cache[ $key ] );
				}
				return true;
			}
		);

		// Escapes y formato.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		// El renglón de editorial enlaza al archive de la marca. Default
		// determinista y NO opcional: Brain Monkey deja el símbolo definido tras
		// el tearDown, así que sin esto el primer test que mockee
		// `get_term_link()` haría que todos los demás recibieran el stub vacío
		// —null— y cayeran silenciosamente al nombre pelado. Verde en orden
		// natural, rojo con --order-by=random.
		Functions\when( 'get_term_link' )->alias(
			static fn( int $term_id, string $taxonomy ): string => 'https://akibara.cl/marca/' . $taxonomy . '-' . $term_id . '/'
		);
		// Estados de obra/edición: vacíos por defecto. No es opcional por la misma
		// razón que `get_term_link`: otra suite puede dejar `get_term_meta()`
		// definido en el proceso, y sin este default las filas aparecerían —o no—
		// según el orden de ejecución.
		Functions\when( 'get_term_meta' )->justReturn( '' );
		// Ficha del tomo del atajo «Último tomo». Mismo motivo que `get_term_link`.
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 'https://akibara.cl/producto-' . $id . '/'
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		Functions\when( 'wc_price' )->alias(
			static fn( $price ): string => '<span class="woocommerce-Price-amount">$' . number_format( (float) $price, 0, ',', '.' ) . '</span>'
		);
		Functions\when( 'wc_format_price_range' )->alias(
			static fn( $from, $to ): string => '<span class="woocommerce-Price-amount">$' . number_format( (float) $from, 0, ',', '.' )
				. '</span> &ndash; <span class="woocommerce-Price-amount">$' . number_format( (float) $to, 0, ',', '.' ) . '</span>'
		);

		// Transients en memoria: el contrato de cacheo se prueba contra un
		// almacén real, no contra expectativas de llamada.
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				$this->transients[ $key ] = $value;
				$this->transients[ $key . '::ttl' ] = $ttl;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ], $this->transients[ $key . '::ttl' ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		// Las pruebas del atajo al último tomo ponen su propia consulta principal.
		unset( $GLOBALS['wp_query'] );
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Término de serie con `term_taxonomy_id` distinto del `term_id`. */
	private function series_term( int $tt_id ): object {
		$term                   = new \WP_Term( 900, 'one-piece', 'One Piece' );
		$term->taxonomy         = 'pa_serie';
		$term->term_taxonomy_id = $tt_id;

		return $term;
	}

	/** @param callable|null $gate Predicado de override de precio. */
	private function render( ?callable $gate = null ): string {
		ob_start();
		( new SeriesFacts( $gate ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Lo que ve la petición SIGUIENTE: sin transients y sin lo que la anterior
	 * ya había verificado (QA-BIZ-DIFF-3: los hechos verificados se reusan
	 * dentro de una petición, y en el sitio cada petición es otro proceso).
	 */
	private function nueva_peticion(): void {
		$this->transients = array();
		$memo             = new \ReflectionProperty( SeriesFacts::class, 'verified_facts' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}

	/** Texto plano del bloque, con espacios colapsados. */
	private function text( string $html ): string {
		return (string) preg_replace( '/\s+/', ' ', trim( strip_tags( $html ) ) );
	}

	/**
	 * Carga los estados separados de obra y edición como si el dueño los hubiera
	 * guardado desde el formulario.
	 *
	 * Responde por CLAVE y no con un valor fijo: las cuatro metas se leen en la
	 * misma llamada y un `justReturn` escondería una mezcla de alcances.
	 *
	 * @param string $work_state   Estado de la obra original.
	 * @param string $work_total   Total de la obra tal como sale de `wp_termmeta`.
	 * @param string $edition_state Estado de la edición vendida.
	 * @param string $edition_total Total anunciado de la edición.
	 */
	private function publication(
		string $work_state = '',
		string $work_total = '',
		string $edition_state = '',
		string $edition_total = ''
	): void {
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = false ) use ( $work_state, $work_total, $edition_state, $edition_total ): string {
				unset( $term_id, $single );

				$values = array(
					PublicationStatus::META_WORK_STATE    => $work_state,
					PublicationStatus::META_WORK_TOTAL    => $work_total,
					PublicationStatus::META_EDITION_STATE => $edition_state,
					PublicationStatus::META_EDITION_TOTAL => $edition_total,
				);

				if ( array_key_exists( $key, $values ) ) {
					return $values[ $key ];
				}

				return '';
			}
		);
	}

	/**
	 * La ficha entera iba sobre la rejilla y empujaba el primer tomo a 1.353 px
	 * en móvil (auditoría del tema, UM-1). Ahora arriba va sólo el resumen —prio 2:
	 * el theme abre `.akb-shop-toolbar` en 4 y en 3 conviven otros bloques cuyo
	 * orden decidiría la instanciación— y la ficha baja tras la paginación de
	 * WooCommerce (10) y antes de la guía de lectura (25).
	 *
	 * @testdox Should hook the one-line summary above the toolbar and the full sheet below the grid.
	 */
	public function test_registers_the_summary_above_and_the_sheet_below_the_grid(): void {
		$module = new SeriesFacts();

		Monkey\Actions\expectAdded( 'woocommerce_before_shop_loop' )
			->once()
			->with( array( $module, 'render_summary' ), 2 );
		Monkey\Actions\expectAdded( 'woocommerce_after_shop_loop' )
			->once()
			->with( array( $module, 'render' ), 20 );

		$module->register();

		self::assertTrue( true, 'Resumen y ficha quedaron colgados de sus hooks.' );
	}

	/**
	 * @testdox Should print nothing outside a pa_serie archive.
	 */
	public function test_prints_nothing_outside_a_series_archive(): void {
		Functions\when( 'is_tax' )->justReturn( false );

		self::assertSame( '', $this->render(), 'Fuera de pa_serie el bloque no existe.' );
		self::assertSame( array(), $this->wpdb->queries, 'Ni siquiera debe consultar la base.' );
	}

	/**
	 * `is_tax( 'pa_serie' )` puede ser true mientras el objeto consultado es
	 * otra cosa (o nada) en caminos raros de ruteo. La ficha declara datos DE UN
	 * TÉRMINO: sin término no hay nada que declarar.
	 *
	 * @testdox Should print nothing when the queried object is not a series term.
	 */
	public function test_prints_nothing_without_a_series_term(): void {
		Functions\when( 'get_queried_object' )->justReturn( null );
		self::assertSame( '', $this->render() );

		$other           = $this->series_term( 378 );
		$other->taxonomy = 'product_brand';
		Functions\when( 'get_queried_object' )->justReturn( $other );
		self::assertSame( '', $this->render() );

		self::assertSame( array(), $this->wpdb->queries );
	}

	/**
	 * La ficha vale para TODA variante del archive de la serie, y esto no es una
	 * comodidad: es lo que impide una incoherencia real.
	 *
	 * El buscador propio (`akibara-search`) navega este archive por PJAX y sólo
	 * reemplaza una lista fija de contenedores —`ul.products`,
	 * `.woocommerce-pagination`, `.akb-facets`, `.akb-facet-chips`,
	 * `.akb-shop-toolbar`, `.akb-empty-state`, `.page-title`— entre los que este
	 * bloque no está. Si el bloque sólo se imprimiera en la URL canónica, un
	 * click en la paginación lo dejaría en pantalla con la URL ya en /page/2/,
	 * mientras que un F5 en esa misma dirección lo haría desaparecer: la misma
	 * URL sirviendo dos DOM distintos según cómo se llegó.
	 *
	 * Todo lo que declara es un hecho de la SERIE, no del subconjunto mostrado,
	 * así que es igual de cierto en la página 1, en la 4 y bajo cualquier faceta.
	 * El único dato que sí dependía del subconjunto era el total, y por eso se
	 * eliminó (ver `test_does_not_repeat_the_result_count`).
	 *
	 * @testdox Should print the same block on filtered and paginated variants.
	 */
	public function test_prints_the_same_block_on_every_variant(): void {
		$canonical = $this->render();
		self::assertNotSame( '', $canonical );

		$_GET = array( '_disponibilidad' => 'instock' );
		self::assertSame( $canonical, $this->render(), 'Los hechos de la serie no cambian con una faceta.' );

		$_GET                      = array();
		$this->query_vars['paged'] = 2;
		self::assertSame( $canonical, $this->render(), 'Los hechos de la serie no cambian de página.' );

		$_GET = array( 'orderby' => 'price' );
		self::assertSame( $canonical, $this->render(), 'Ordenar no altera un solo dato de la ficha.' );
	}

	/**
	 * El total NO se imprime: ya lo publica la barra de herramientas dos líneas
	 * más abajo (`<p class="akb-result-count">107 tomos</p>`). Repetirlo sería el
	 * mismo defecto por el que el theme retiró el "%s títulos" del hero —dos
	 * números idénticos en unidad y significado, uno estático y otro vivo—, y
	 * además es el único dato que dependía del subconjunto mostrado.
	 *
	 * @testdox Should not repeat the result count already printed by the toolbar.
	 */
	public function test_does_not_repeat_the_result_count(): void {
		$text = $this->text( $this->render() );

		self::assertStringNotContainsString( 'Tomos en catálogo', $text );
		self::assertStringNotContainsString( '107', $text, 'El total vive en el contador de la toolbar, no acá.' );
		// El desglose por estado sí está, y suma ese total sin afirmarlo.
		self::assertStringContainsString( 'Disponible 31 tomos', $text );
		self::assertStringContainsString( 'A pedido 76 tomos', $text );
	}

	/**
	 * @testdox Should print nothing when the series has no published products.
	 */
	public function test_prints_nothing_when_the_series_has_no_products(): void {
		$this->wpdb->row = array(
			'tomos'       => '0',
			'instock'     => '0',
			'preventa'    => '0',
			'agotados'    => '0',
			'price_min'   => null,
			'price_max'   => null,
			'updated'     => null,
			'editoriales' => null,
		);

		self::assertSame( '', $this->render(), 'Una serie sin catálogo no tiene ningún hecho que declarar.' );
	}

	/**
	 * @testdox Should print nothing when the aggregate query returns nothing at all.
	 */
	public function test_prints_nothing_when_the_query_returns_nothing(): void {
		$this->wpdb->row = null;

		self::assertSame( '', $this->render() );
	}

	/**
	 * La obra original de The Walking Dead terminó, pero la edición integral de
	 * Planeta sigue en publicación y anunció ocho tomos. Akibara tiene cuatro.
	 * Son tres alcances distintos y ninguno puede heredar el estado de otro.
	 *
	 * @testdox Should render The Walking Dead as an ongoing Planeta edition of a finished work, followed by four available volumes.
	 */
	public function test_renders_the_walking_dead_with_three_explicit_scopes(): void {
		$this->wpdb->row = array(
			'tomos'               => '4',
			'instock'             => '4',
			'preventa'            => '0',
			'agotados'            => '0',
			'agotados_titulos'    => null,
			'editoriales'         => "\x1ePlaneta Cómic\x1f230",
		) + self::ONE_PIECE;
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '8' );

		$html = $this->render();
		$text = $this->text( $html );

		self::assertStringStartsWith(
			'Edición Planeta Cómic En publicación 8 tomos previstos Obra original Finalizada Disponible 4 tomos',
			$text
		);
		self::assertStringContainsString(
			'En publicación <span class="akb-series-facts__note">8 tomos previstos</span>',
			$html,
			'El detalle sigue siendo una sola frase en accesibilidad aunque el CSS lo parta visualmente.'
		);
		self::assertStringNotContainsString( 'Completa', $text, 'Ni la obra finalizada ni el stock convierten la edición en completa.' );
	}

	/**
	 * Si obra y edición dicen lo mismo, la segunda fila sería ruido. La edición
	 * queda primero porque es la entidad exacta que agrupa los productos.
	 *
	 * @testdox Should omit the original-work row when it adds neither a different state nor a different total.
	 */
	public function test_omits_a_redundant_original_work_row(): void {
		$this->publication( PublicationStatus::ONGOING, '', PublicationStatus::ONGOING, '8' );

		$text = $this->text( $this->render() );

		self::assertStringStartsWith( 'Edición Ivrea Argentina En publicación 8 tomos previstos Disponible', $text );
		self::assertStringNotContainsString( 'Obra original', $text );
	}

	/**
	 * Un total guardado para una obra no finalizada no es publicable y por tanto
	 * tampoco puede hacer aparecer una segunda fila redundante.
	 *
	 * @testdox Should ignore a latent unfinished-work total when deduplicating the rows.
	 */
	public function test_ignored_unfinished_work_total_does_not_change_the_rows(): void {
		$this->publication( PublicationStatus::ONGOING, '23', PublicationStatus::ONGOING, '8' );

		$text = $this->text( $this->render() );

		self::assertStringStartsWith( 'Edición Ivrea Argentina En publicación 8 tomos previstos Disponible', $text );
		self::assertStringNotContainsString( 'Obra original', $text );
	}

	/**
	 * El estado puede coincidir y aun así los totales identificar dos entidades
	 * distintas. En ese caso esconder la obra perdería información verificable.
	 *
	 * @testdox Should keep the original-work row when its total differs from the edition total.
	 */
	public function test_keeps_the_original_work_row_when_its_total_differs(): void {
		$this->wpdb->row['tomos'] = '17';
		$this->publication( PublicationStatus::COMPLETE, '23', PublicationStatus::COMPLETE, '17' );

		$text = $this->text( $this->render() );

		self::assertStringStartsWith(
			'Edición Ivrea Argentina Completa en 17 tomos Obra original Finalizada en 23 tomos Disponible',
			$text
		);
	}

	/**
	 * La regla 1 del módulo aplicada al único dato que no se deriva: sin fuente,
	 * el bloque queda EXACTAMENTE como estaba. Un defecto por defecto —«En
	 * publicación» porque la mayoría lo está— sería un placeholder publicado 104
	 * veces.
	 *
	 * @testdox Should leave the block untouched while neither scope has publication data.
	 */
	public function test_omits_the_publication_row_without_the_datum(): void {
		$text = $this->text( $this->render() );

		self::assertStringNotContainsString( 'Edición Ivrea Argentina', $text );
		self::assertStringNotContainsString( 'Obra original', $text );
		self::assertStringNotContainsString( 'Completa', $text );
		self::assertStringStartsWith( 'Disponible 31 tomos', $text, 'El bloque arranca donde arrancaba.' );
	}

	/**
	 * El formulario no es la única puerta a `wp_termmeta`: un WP-CLI o un SQL
	 * pueden escribir cualquier cosa. El enum se valida también al LEER.
	 *
	 * @testdox Should refuse a stored state that is not one of the closed values.
	 */
	public function test_refuses_a_publication_state_outside_the_enum(): void {
		$this->publication( 'terminada', '23' );

		$text = $this->text( $this->render() );

		self::assertStringNotContainsString( 'Edición Ivrea Argentina', $text );
		self::assertStringNotContainsString( 'Obra original', $text );
		self::assertStringNotContainsString( 'terminada', $text );
		self::assertStringNotContainsString( '23 tomos', $text, 'Sin estado válido, el total tampoco se publica.' );
	}

	/**
	 * Obra original y catálogo de la edición son ejes distintos: una edición puede
	 * dividir, recopilar o empaquetar el material en otra cantidad de productos.
	 *
	 * @testdox Should not compare a work total with the edition product count.
	 */
	public function test_does_not_compare_work_total_with_edition_catalogue_count(): void {
		$this->publication( PublicationStatus::COMPLETE, '5' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Obra original Finalizada en 5 tomos', $text );
	}

	/**
	 * @testdox Should print the real counts of the series broken down by stock state.
	 */
	public function test_prints_the_real_counts(): void {
		$text = $this->text( $this->render() );

		// El vocabulario sale de Stock\StockStatus::LABELS, la fuente única de
		// las palabras que también rotulan las cards de abajo.
		self::assertStringContainsString( 'Disponible 31 tomos', $text );
		self::assertStringContainsString( 'A pedido 76 tomos', $text );
		// Sin agotados el renglón no existe: su única función es cuadrar la suma.
		self::assertStringNotContainsString( 'Agotado', $text );
	}

	/**
	 * Sin este renglón, una serie con tomos descatalogados publicaría 107 arriba
	 * y 31 + 64 = 95 abajo, y el lector concluiría —con razón— que el bloque
	 * miente. 26 de las 101 series con catálogo están en ese caso.
	 *
	 * @testdox Should add the sold-out count only when it exists, so the numbers add up.
	 */
	public function test_adds_the_sold_out_count_only_when_it_exists(): void {
		$this->wpdb->row = array(
			'tomos'    => '107',
			'instock'  => '31',
			'preventa' => '64',
			'agotados' => '12',
		) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 12 tomos', $text );
	}

	/**
	 * Fila de The Climber: 4 agotados, y los títulos EN DESORDEN a propósito.
	 *
	 * El orden en que MySQL devuelve un `GROUP_CONCAT` sin `ORDER BY` no está
	 * garantizado, y el orden que sí se probó en producción —`post_title`— es
	 * lexicográfico: "11, 3, 6, 8". Si el doble entregara la lista ya ordenada,
	 * el test pasaría aunque el módulo no ordenara nada.
	 *
	 * @param string $titles Títulos separados por <RS>.
	 * @param string $count  Conteo de agotados del agregado.
	 * @return array<string,mixed>
	 */
	private function sold_out_row( string $titles, string $count ): array {
		return array(
			'tomos'            => '17',
			'instock'          => '8',
			'preventa'         => '5',
			'agotados'         => $count,
			'agotados_titulos' => $titles,
		) + self::ONE_PIECE;
	}

	/**
	 * ── El hallazgo que este renglón cierra ─────────────────────────────────
	 * "Agotado: 4 tomos" deja al lector con la peor mitad del dato. En The
	 * Climber los agotados son el 03, 06, 08 y 11 mientras el 01 y el 02 están
	 * disponibles: quien llega desde Google compra los dos primeros y choca con
	 * el tercero. El dato existía —los badges de las cards— pero repartido en 17
	 * fichas a lo largo de 6.432 px de scroll.
	 *
	 * @testdox Should name which volumes are sold out, in numeric order.
	 */
	public function test_names_the_sold_out_volumes(): void {
		$this->wpdb->row = $this->sold_out_row(
			"The Climber 11\x1eThe Climber 03\x1eThe Climber 08\x1eThe Climber 06",
			'4'
		);

		$text = $this->text( $this->render() );

		// Numérico, no lexicográfico: `ORDER BY post_title` habría publicado
		// "11, 03, 06, 08" y el 11 primero es exactamente el error que hace
		// inútil la lista.
		self::assertStringContainsString( 'Agotado 4 tomos: 03, 06, 08 y 11', $text );
	}

	/**
	 * ── Por qué una edición especial agotada NO puede vaciar la lista ───────
	 * «Demon Slayer 23 (Edición Especial)» no matchea `VOLUME_PATTERN`, y la
	 * regla «todos o ninguno» vaciaba la lista de TODA la serie por ese único
	 * título: la landing perdía el renglón «Agotado: 03, 06, 08 y 11» el día en
	 * que se vendiera la última unidad del especial, sin error ni aviso.
	 *
	 * La variante se descarta en el agregado, y con ella su conteo: la lista y
	 * el rótulo salen del MISMO filtro, así que nunca pueden desmentirse.
	 *
	 * @testdox A sold-out variant of the same volume must not empty the list.
	 */
	public function test_una_variante_agotada_no_vacia_la_lista(): void {
		// El agregado ya filtró la variante: cuatro tomos agotados con nombre y
		// un quinto PRODUCTO agotado que no es un tomo.
		$this->wpdb->row = array(
			'productos_agotados' => '5',
		) + $this->sold_out_row(
			"The Climber 11\x1eThe Climber 03\x1eThe Climber 08\x1eThe Climber 06",
			'4'
		);

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 4 tomos: 03, 06, 08 y 11', $text );
	}

	/**
	 * El conteo NO se reemplaza por la lista: su función es que la suma cuadre
	 * con el total de la toolbar (8 + 5 + 4 = 17). Sin él, el lector que cuenta
	 * los números nombrados estaría haciendo el trabajo del bloque.
	 *
	 * @testdox Should keep the count next to the named volumes so the arithmetic still adds up.
	 */
	public function test_keeps_the_count_next_to_the_named_volumes(): void {
		$this->wpdb->row = $this->sold_out_row( "The Climber 03\x1eThe Climber 06", '2' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 2 tomos: 03 y 06', $text );
		self::assertStringContainsString( 'Disponible 8 tomos', $text );
		self::assertStringContainsString( 'A pedido 5 tomos', $text );
	}

	/**
	 * El número se publica TAL CUAL lo escribe el título, con su padding: la card
	 * de abajo estampa "03" en el lomo amarillo (`akb-title-name` + `akb-vol` del
	 * theme) y de ese padding vive el orden alfabético del archive. Normalizarlo
	 * a "3" obligaría al lector a traducir entre la ficha y la rejilla.
	 *
	 * @testdox Should print the volume number exactly as the catalogue writes it, padding included.
	 */
	public function test_prints_the_volume_number_with_its_padding(): void {
		$this->wpdb->row = $this->sold_out_row( "One Piece 007\x1eOne Piece 024", '2' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( '007 y 024', $text );
		self::assertStringNotContainsString( '7 y 24', $text );
	}

	/**
	 * Un solo tomo agotado no lleva "y": "1 tomo: 03".
	 *
	 * @testdox Should name a single sold-out volume without a conjunction.
	 */
	public function test_names_a_single_sold_out_volume(): void {
		$this->wpdb->row = $this->sold_out_row( 'The Climber 03', '1' );

		self::assertStringContainsString( 'Agotado 1 tomo: 03', $this->text( $this->render() ) );
	}

	/**
	 * Claymore tiene 19 agotados de 27 tomos. Listarlos todos convierte un dato
	 * en un párrafo dentro de un `<dd>` de ~140 px, que es el ancho real de la
	 * rejilla de la ficha en móvil (72,5% del tráfico). Se nombran los primeros
	 * —la pregunta que decide la compra es "¿puedo empezar?"— y el resto se
	 * resume sin dejar de ser exacto.
	 *
	 * @testdox Should summarize instead of listing forty numbers.
	 */
	public function test_summarizes_a_long_sold_out_list(): void {
		$titles = array();
		foreach ( range( 1, 19 ) as $n ) {
			$titles[] = sprintf( 'Claymore %02d', $n );
		}
		$this->wpdb->row = $this->sold_out_row( implode( "\x1e", $titles ), '19' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 19 tomos: 01, 02, 03, 04, 05, 06 y 13 más', $text );
	}

	/**
	 * Con UN solo sobrante, "y 1 más" ocupa más que el propio número y esconde un
	 * dato por nada. El resumen sólo entra a partir de dos.
	 *
	 * @testdox Should name every volume when the summary would hide a single one.
	 */
	public function test_does_not_summarize_a_single_leftover(): void {
		$titles = array();
		foreach ( range( 1, 7 ) as $n ) {
			$titles[] = sprintf( 'Claymore %02d', $n );
		}
		$this->wpdb->row = $this->sold_out_row( implode( "\x1e", $titles ), '7' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( '01, 02, 03, 04, 05, 06 y 07', $text );
		self::assertStringNotContainsString( 'más', $text );
	}

	/**
	 * ── Todo o nada ─────────────────────────────────────────────────────────
	 * Una lista incompleta responde una pregunta distinta y peor: quien lee
	 * "Agotado: 4 tomos: 03 y 06" concluye que el 08 y el 11 se consiguen, y
	 * compra creyendo que puede terminar la serie. El conteo pelado no dice
	 * cuáles, pero tampoco miente — y es exactamente el renglón que había antes
	 * de este cambio.
	 *
	 * @testdox Should fall back to the bare count when any title has no volume number.
	 */
	public function test_falls_back_to_the_bare_count_when_a_title_has_no_number(): void {
		$this->wpdb->row = $this->sold_out_row(
			"Akira 03\x1eAkira. Edición Original\x1eAkira 06\x1eAkira 08",
			'4'
		);

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 4 tomos', $text );
		self::assertStringNotContainsString( '03', $text );
		self::assertStringNotContainsString( ':', $text );
	}

	/**
	 * El `DISTINCT` del `GROUP_CONCAT` colapsa dos títulos idénticos, y dos tomos
	 * distintos con el MISMO número ("03" y "03") no se pueden nombrar sin
	 * publicar un dato falso. En los dos casos quedan menos números que agotados
	 * contó el agregado, y esa desigualdad es la señal.
	 *
	 * @testdox Should fall back to the bare count when the parsed volumes do not match the count.
	 */
	public function test_falls_back_when_the_volumes_do_not_match_the_count(): void {
		// Tres títulos para cuatro agotados: uno se perdió por el camino.
		$this->wpdb->row = $this->sold_out_row(
			"The Climber 03\x1eThe Climber 06\x1eThe Climber 08",
			'4'
		);
		self::assertStringNotContainsString( '03', $this->text( $this->render() ) );

		// Dos títulos distintos que dan el mismo número: "03, 03" sería falso.
		$this->nueva_peticion();
		$this->wpdb->row  = $this->sold_out_row( "The Climber 03\x1eThe Climber Gaiden 3", '2' );
		$text             = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 2 tomos', $text );
		self::assertStringNotContainsString( '03', $text );
	}

	/**
	 * `group_concat_max_len` recorta el resultado SIN error y sin aviso. Si el
	 * corte cae DENTRO del último título sin eliminar ninguno, el conteo cuadra
	 * igual y el módulo publicaría un número mutilado ("The Climber 1" por "The
	 * Climber 11") — el tomo equivocado, que es peor que ningún tomo. Por eso la
	 * primera puerta es la longitud, antes de mirar el contenido.
	 *
	 * @testdox Should refuse a title list long enough to have been truncated by the database.
	 */
	public function test_refuses_a_possibly_truncated_title_list(): void {
		$titles = array();
		foreach ( range( 1, 60 ) as $n ) {
			$titles[] = sprintf( 'Una serie con un título larguísimo %02d', $n );
		}
		$concat = implode( "\x1e", $titles );
		self::assertGreaterThanOrEqual( 1000, strlen( $concat ), 'El fixture debe superar el umbral de sospecha.' );

		$this->wpdb->row = $this->sold_out_row( $concat, '60' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 60 tomos', $text );
		self::assertStringNotContainsString( ':', $text );
	}

	/**
	 * Disponible y Preventa son lo que el lector puede comprar hoy y la rejilla
	 * los muestra primero; el que el orden por defecto esconde al final es el
	 * agotado. Nombrar los tres estados triplicaría el alto del bloque sin
	 * responder ninguna pregunta nueva.
	 *
	 * @testdox Should name volumes only for the sold-out row.
	 */
	public function test_names_volumes_only_for_the_sold_out_row(): void {
		$this->wpdb->row = $this->sold_out_row( "The Climber 03\x1eThe Climber 06", '2' );

		$html = $this->render();

		self::assertSame(
			1,
			substr_count( $html, 'akb-series-facts__vols' ),
			'Un solo renglón nombra tomos, y es el de agotados.'
		);
	}

	/**
	 * El orden lo resuelve PHP tras parsear, así que la consulta NO debe pedir un
	 * `ORDER BY post_title`: es lexicográfico y devolvería "11, 3, 6, 8" — el
	 * orden equivocado justo en el dato cuya utilidad es saber por dónde se corta
	 * la serie. Y los títulos se piden SÓLO de los agotados: traerlos todos para
	 * filtrar en PHP movería 107 filas por serie sin necesidad.
	 *
	 * @testdox The title aggregate must not sort lexicographically, and must ask only for sold-out titles.
	 */
	public function test_the_title_aggregate_asks_only_for_sold_out_titles(): void {
		$this->render();
		$sql = $this->wpdb->queries[0];

		// Los títulos se piden con el MISMO filtro que el conteo con el que
		// después se comparan: agotados Y no variantes. Si los dos filtros se
		// separan, la lista y su rótulo dejan de poder cuadrar.
		//
		// El filtro se compone desde `VolumeVariant` a propósito: lo que hay que
		// fijar es que el SQL pregunte por el ENUM CERRADO —no por «distinto de
		// vacío», que ascendía a variante cualquier tipeo— y que siga preguntando
		// lo mismo si mañana aparece un cuarto tipo.
		$tomo_normal = "TRIM( COALESCE( vm.meta_value, '' ) ) NOT IN ( '"
			. implode( "', '", VolumeVariant::variant_kinds() )
			. "' )";

		self::assertStringContainsString( "WHEN l.stock_status = 'outofstock' AND " . $tomo_normal . ' THEN p.post_title', $sql );
		self::assertStringContainsString( "WHEN l.stock_status = 'outofstock' AND " . $tomo_normal . ' THEN p.ID', $sql );
		self::assertStringNotContainsString( "COALESCE( vm.meta_value, '' ) = ''", $sql );

		// Y el candado de posición: los valores de `prepare()` se sustituyen por
		// ORDEN TEXTUAL, así que meter un grupo nuevo de placeholders en mitad
		// del SELECT corre todos los de más abajo. Si el `JOIN` de la marca
		// dejara de decir su propia clave, es que el reparto se desalineó.
		self::assertStringContainsString( "vm.meta_key = '" . VolumeVariant::META_KEY . "'", $sql );
		self::assertStringContainsString( 'tr.term_taxonomy_id = 378', $sql );
		self::assertStringNotContainsString( 'ORDER BY p.post_title', $sql );
		self::assertStringNotContainsString( 'ORDER BY post_title', $sql );
	}

	/**
	 * @testdox Should keep a zero count for a state the series really has.
	 */
	public function test_keeps_a_zero_count_for_a_real_state(): void {
		$this->wpdb->row = array(
			'instock'  => '0',
			'preventa' => '107',
		) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		// "0 disponibles" NO es un hueco: es el dato (la serie entera está en
		// preventa). Ocultarlo dejaría al lector suponiendo.
		self::assertStringContainsString( 'Disponible 0 tomos', $text );
		self::assertStringContainsString( 'A pedido 107 tomos', $text );
	}

	/**
	 * @testdox Should print the price range with the site format, and a single price when both ends match.
	 */
	public function test_prints_the_price_range(): void {
		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Precios $9.500', $text );
		self::assertStringContainsString( '$9.990', $text );

		$this->wpdb->row  = array( 'price_max' => '9500.0000' ) + self::ONE_PIECE;
		$this->nueva_peticion();
		$single           = $this->text( $this->render() );

		self::assertStringContainsString( 'Precio $9.500', $single );
		self::assertStringNotContainsString( '$9.990', $single );
	}

	/**
	 * `wc_product_meta_lookup` guarda el precio en contexto `edit` (sin filtros)
	 * y el descuento editorial de TaxonomyDiscount es filter-only: con una
	 * campaña vigente el rango de la lookup es el precio SIN descuento mientras
	 * las cards de abajo muestran otro número. Se omite antes que contradecir a
	 * la grilla.
	 *
	 * @testdox Should omit the price range while a runtime price override is live.
	 */
	public function test_omits_the_price_range_under_a_runtime_override(): void {
		$text = $this->text( $this->render( static fn (): bool => true ) );

		self::assertStringNotContainsString( '9.500', $text );
		self::assertStringNotContainsString( 'Precio', $text );
		// El resto de la ficha no depende del precio y sigue publicándose.
		self::assertStringContainsString( 'Ivrea Argentina', $text );
	}

	/**
	 * @testdox Should print the publisher from product_brand, the verified editorial taxonomy.
	 */
	public function test_prints_the_publisher_from_product_brand(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Editorial', $html );
		self::assertStringContainsString( 'Ivrea Argentina', $html );

		// `pa_editorial` NO existe (un intento contra ella devolvía Array
		// vacío). El literal que viaja al SQL es la prueba de que se consulta la
		// taxonomía correcta; sin esto, un typo silencioso dejaría la ficha sin
		// editorial y el bloque igual saldría verde.
		self::assertStringContainsString( "btt2.taxonomy = 'product_brand'", $this->wpdb->queries[0] );
		self::assertStringNotContainsString( 'pa_editorial', $this->wpdb->queries[0] );
	}

	/**
	 * La fila editorial puede listar todas las marcas del catálogo, pero una meta
	 * de edición no se puede atribuir honestamente a ninguna cuando hay varias.
	 *
	 * @testdox Should list every publisher but fail closed for edition identity when there is more than one.
	 */
	public function test_joins_every_publisher(): void {
		$this->wpdb->row = array( 'editoriales' => "Ivrea Argentina\x1f230\x1ePanini Manga\x1f231" ) + self::ONE_PIECE;
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '8' );

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Editoriales Ivrea Argentina, Panini Manga', $text );
		self::assertStringNotContainsString( 'Edición Ivrea Argentina', $text );
		self::assertStringNotContainsString( 'Edición Panini Manga', $text );
		self::assertStringStartsWith( 'Obra original Finalizada Disponible', $text );
	}

	/**
	 * Cero editoriales deja el mismo problema de identidad que varias: la edición
	 * se calla, pero la obra original no depende de una marca para ser cierta.
	 *
	 * @testdox Should omit the edition row without a publisher while retaining original-work data.
	 */
	public function test_omits_the_edition_row_without_a_publisher(): void {
		$this->wpdb->row = array( 'editoriales' => null ) + self::ONE_PIECE;
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '8' );

		$text = $this->text( $this->render() );

		self::assertStringNotContainsString( 'Edición ', $text );
		self::assertStringStartsWith( 'Obra original Finalizada Disponible', $text );
	}

	/**
	 * ── Reversión deliberada ────────────────────────────────────────────────
	 * Este renglón imprimía el nombre pelado porque `get_term_link()` puede
	 * devolver `WP_Error`. La premisa era cierta y la conclusión no: la landing
	 * de serie no enlazaba a ninguna otra serie ni a la editorial, o sea era un
	 * callejón sin salida en 106 páginas que reciben 4.044 impresiones cada 90
	 * días. El enlace se recupera, y el caso degradado sigue siendo exactamente
	 * el comportamiento anterior (ver el test siguiente).
	 *
	 * @testdox Should link the publisher to its brand archive.
	 */
	public function test_links_the_publisher_to_its_brand_archive(): void {
		$received = array();
		Functions\when( 'get_term_link' )->alias(
			static function ( $term_id, $taxonomy ) use ( &$received ): string {
				$received[] = array( $term_id, $taxonomy );
				return 'https://akibara.cl/marca/ivrea-argentina/';
			}
		);

		$html = $this->render();

		// El id y la taxonomía salen del MISMO agregado que imprime el nombre:
		// se resuelve `product_brand`, la taxonomía verificada, nunca la
		// inexistente `pa_editorial`. Y cada renglón resuelve la SUYA: un solo
		// `get_term_link()` con la taxonomía fija enlazaría al autor dentro de
		// /marca/. El orden es el del DOM (autor antes que editorial).
		self::assertSame(
			array( array( 244, 'pa_autor' ), array( 230, 'product_brand' ) ),
			$received
		);

		self::assertStringContainsString(
			'<a class="akb-series-facts__link" href="https://akibara.cl/marca/ivrea-argentina/">Ivrea Argentina</a>',
			$html
		);
	}

	/**
	 * @testdox Should fall back to the bare publisher name when the term link fails.
	 */
	public function test_falls_back_to_the_bare_name_when_the_term_link_fails(): void {
		Functions\when( 'get_term_link' )->justReturn( new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );

		$html = $this->render();

		self::assertStringNotContainsString( '<a', $html, 'Un enlace roto cuesta más que el enlace que ahorra.' );
		self::assertStringContainsString( 'Ivrea Argentina', $html, 'El dato se publica igual: lo que se pierde es el enlace.' );
	}

	/**
	 * El id llega pegado al nombre en el MISMO `GROUP_CONCAT`. Dos listas
	 * paralelas ordenadas por separado se desalinean en cuanto una serie tiene
	 * más de una editorial, y ahí el renglón enlazaría el nombre de una marca a
	 * la landing de OTRA — un error que ningún conteo detecta y que un lector sí.
	 *
	 * @testdox Should pair each publisher name with its own term id.
	 */
	public function test_pairs_each_publisher_with_its_own_term_id(): void {
		$this->wpdb->row = array( 'editoriales' => "Ivrea Argentina\x1f230\x1ePanini Manga\x1f231" ) + self::ONE_PIECE;

		$html = $this->render();

		self::assertStringContainsString( 'href="https://akibara.cl/marca/product_brand-230/">Ivrea Argentina</a>', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/marca/product_brand-231/">Panini Manga</a>', $html );
	}

	/**
	 * MySQL exige que el `ORDER BY` de un `GROUP_CONCAT(DISTINCT …)` sea la
	 * MISMA expresión concatenada. Ordenar por `bt.name` a secas mientras se
	 * concatena el par es un error de ejecución (no un resultado feo): la
	 * agregación entera devolvería null y la ficha desaparecería de las 106
	 * landings. Verificado contra MariaDB 11.4 en el Docker local.
	 *
	 * @testdox The publisher aggregate must order by the very expression it concatenates.
	 */
	public function test_orders_the_publisher_aggregate_by_the_concatenated_expression(): void {
		$this->render();
		$sql = $this->wpdb->queries[0];

		self::assertStringContainsString( 'DISTINCT CONCAT_WS(', $sql );
		self::assertSame(
			2,
			substr_count( $sql, 'CONCAT_WS(\'' . "\x1f" . '\', bt.name, bt.term_id)' ),
			'La expresión concatenada y la de orden deben ser idénticas.'
		);
		self::assertStringNotContainsString( 'ORDER BY bt.name', $sql );
	}

	/**
	 * ── Por qué existe esta fila ────────────────────────────────────────────
	 * La landing era un callejón sin salida medido: 23 enlaces en `<main>` y 21
	 * apuntando a los mismos 17 tomos; la única salida lateral era la editorial y
	 * 0 de 104 landings enlazan a otra serie. El archive del autor ya existe y ya
	 * está indexado, así que son 106 enlaces internos nuevos sin escribir una
	 * línea de contenido.
	 *
	 * @testdox Should print the author linked to its own pa_autor archive.
	 */
	public function test_links_the_author_to_its_archive(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Autor', $html );
		self::assertStringContainsString(
			'<a class="akb-series-facts__link" href="https://akibara.cl/marca/pa_autor-244/">Eiichiro Oda</a>',
			$html
		);

		// La taxonomía viaja al SQL: un typo dejaría la fila muda y el bloque
		// saldría igual de verde que hoy.
		self::assertStringContainsString( "autt2.taxonomy = 'pa_autor'", $this->wpdb->queries[0] );
	}

	/**
	 * El filtro de taxonomía tiene que acotar el JOIN de RELACIONES, no sólo el de
	 * `term_taxonomy`.
	 *
	 * Escrito al revés —que es como estaba—, `btr` y `autr` traían TODAS las
	 * relaciones de término del producto (serie, categorías, formato, demografía,
	 * idioma, visibilidad…) y `term_taxonomy` sólo convertía en NULL las que no
	 * eran suyas: las FILAS ya estaban formadas. La multiplicación no era «tomos ×
	 * marcas × autores» como afirmaba el docblock, sino tomos × R² con R = todas
	 * las relaciones del producto (10,09 de promedio en la base local, máximo 14).
	 * Medido sobre One Piece —107 tomos, 1 marca, 1 autor—: 10.721 filas
	 * intermedias contra 107 con el filtro en su sitio, con agregados idénticos en
	 * las 101 series con catálogo.
	 *
	 * Nada de esto se ve en la ficha: todos los agregados son `COUNT(DISTINCT)`,
	 * `GROUP_CONCAT(DISTINCT)` o `MIN`/`MAX`. Por eso hace falta fijarlo acá: es un
	 * defecto de costo que ninguna aserción de salida puede atrapar, y su docblock
	 * es la base sobre la que alguien va a decidir si entra una tercera taxonomía.
	 *
	 * @testdox Should filter the taxonomy on the relationship join, not only on term_taxonomy.
	 */
	public function test_taxonomy_filter_bounds_the_relationship_join(): void {
		$this->render();

		$sql = (string) $this->wpdb->queries[0];

		foreach ( array( 'btr' => 'btt2', 'autr' => 'autt2' ) as $relation => $subquery ) {
			self::assertMatchesRegularExpression(
				'/' . $relation . '\.term_taxonomy_id IN \(\s*SELECT ' . $subquery . '\.term_taxonomy_id/',
				$sql,
				sprintf( 'El JOIN de %s tiene que traer SÓLO las relaciones de su taxonomía.', $relation )
			);
		}

		// Y el JOIN de `term_taxonomy` ya no filtra: si volviera a hacerlo, sería la
		// señal de que alguien deshizo el acote y el producto cartesiano volvió.
		self::assertStringNotContainsString( "btt.taxonomy =", $sql );
		self::assertStringNotContainsString( "autt.taxonomy =", $sql );
	}

	/**
	 * Autor ANTES que editorial: los dos responden "quién hizo esto", pero el
	 * autor es la intención de lectura más fuerte del lector de manga. El orden
	 * del DOM es contrato, no casualidad del orden de los métodos.
	 *
	 * @testdox Should place the author row above the publisher one.
	 */
	public function test_places_the_author_above_the_publisher(): void {
		$html = $this->render();

		self::assertLessThan(
			(int) strpos( $html, 'Editorial' ),
			(int) strpos( $html, 'Autor' ),
			'El autor va primero: es la salida lateral que la landing no tenía.'
		);
	}

	/**
	 * ── La regla cuando hay varios autores ──────────────────────────────────
	 * Se listan TODOS y no se elige un "principal": el catálogo no declara
	 * ninguna jerarquía entre términos de `pa_autor` (no hay orden en
	 * `wp_term_relationships` ni meta de rol guion/dibujo), así que quedarse con
	 * el primero sería inventar una jerarquía y publicarla como hecho — lo que
	 * prohíbe la regla 1. En manga el par guionista/dibujante es información: 12
	 * series del catálogo local tienen 2 o más.
	 *
	 * @testdox Should list every author when the series has more than one.
	 */
	public function test_lists_every_author(): void {
		$this->wpdb->row = array( 'autores' => "Haro Aso\x1f501\x1eKotaro Takata\x1f502" ) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Autores Haro Aso y Kotaro Takata', $text );
	}

	/**
	 * El máximo real no es dos: Hellblazer tiene 10 autores y La Cosa del Pantano
	 * 5. Sin tope, esa sola fila mediría más que las otras cinco de la ficha
	 * juntas en una rejilla que en móvil es de dos columnas de ~140 px.
	 *
	 * @testdox Should summarize the author list when the series has a whole studio behind it.
	 */
	public function test_summarizes_a_long_author_list(): void {
		$pairs = array();
		foreach ( range( 1, 10 ) as $n ) {
			$pairs[] = sprintf( "Autor %02d\x1f%d", $n, 600 + $n );
		}
		$this->wpdb->row = array( 'autores' => implode( "\x1e", $pairs ) ) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Autores Autor 01, Autor 02, Autor 03 y 7 más', $text );
	}

	/**
	 * El id se empareja con SU nombre dentro del mismo `GROUP_CONCAT`: dos listas
	 * paralelas se desalinean en cuanto una serie tiene más de un autor, y ahí la
	 * fila enlazaría a un autor con el nombre de otro.
	 *
	 * @testdox Should pair each author name with its own term id.
	 */
	public function test_pairs_each_author_with_its_own_term_id(): void {
		$this->wpdb->row = array( 'autores' => "Haro Aso\x1f501\x1eKotaro Takata\x1f502" ) + self::ONE_PIECE;

		$html = $this->render();

		self::assertStringContainsString( 'href="https://akibara.cl/marca/pa_autor-501/">Haro Aso</a>', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/marca/pa_autor-502/">Kotaro Takata</a>', $html );
	}

	/**
	 * Mismo trato que la editorial: la taxonomía de autores tiene la misma trampa
	 * de MySQL, que exige que el `ORDER BY` de un `GROUP_CONCAT(DISTINCT …)` sea
	 * la misma expresión concatenada. Ordenar por `aut.name` a secas no da un
	 * resultado feo: es un error de ejecución que devolvería null en TODA la
	 * agregación y borraría la ficha de las 106 landings.
	 *
	 * @testdox The author aggregate must order by the very expression it concatenates.
	 */
	public function test_orders_the_author_aggregate_by_the_concatenated_expression(): void {
		$this->render();
		$sql = $this->wpdb->queries[0];

		self::assertSame(
			2,
			substr_count( $sql, 'CONCAT_WS(\'' . "\x1f" . '\', aut.name, aut.term_id)' ),
			'La expresión concatenada y la de orden deben ser idénticas.'
		);
		self::assertStringNotContainsString( 'ORDER BY aut.name', $sql );
	}

	/**
	 * Una serie sin autor asignado no imprime la fila —regla 1— y el resto del
	 * bloque sigue igual. Es el estado de partida de las series de las que sólo
	 * se conoce la editorial.
	 *
	 * @testdox Should omit the author row when no author is assigned, without touching the rest.
	 */
	public function test_omits_the_author_row_when_there_is_none(): void {
		$this->wpdb->row = array( 'autores' => null ) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		self::assertStringNotContainsString( 'Autor', $text );
		self::assertStringContainsString( 'Editorial Ivrea Argentina', $text );
		self::assertStringContainsString( 'Disponible 31 tomos', $text );
	}

	/**
	 * Regla dura del encargo: un dato que no se puede derivar se OMITE, nunca se
	 * rellena. Acá faltan las tres cosas omitibles a la vez.
	 *
	 * @testdox Should omit missing data instead of inventing a placeholder.
	 */
	public function test_omits_missing_data_instead_of_inventing_it(): void {
		$this->wpdb->row = array(
			'tomos'       => '12',
			'instock'     => '12',
			'preventa'    => '0',
			'agotados'    => '0',
			'price_min'   => null,   // Sin precio cargado.
			'price_max'   => null,
			'updated'     => null,   // Sin fecha.
			'editoriales' => null,   // Sin marca asignada.
		);

		$html = $this->render();
		$text = $this->text( $html );

		self::assertStringNotContainsString( 'Editorial', $text );
		self::assertStringNotContainsString( 'Autor', $text );
		self::assertStringNotContainsString( 'Precio', $text );
		self::assertStringNotContainsString( 'Actualizado', $text );
		self::assertStringNotContainsString( '<time', $html );
	}

	/**
	 * @testdox Should discard a half price range instead of publishing one open end.
	 */
	public function test_discards_a_half_price_range(): void {
		$this->wpdb->row = array( 'price_max' => '0.0000' ) + self::ONE_PIECE;

		self::assertStringNotContainsString( 'Precio', $this->text( $this->render() ) );
	}

	/**
	 * Gatekeeper medido (252.000 ensayos, 6 LLMs): un timestamp ausente es uno
	 * de los cuatro factores con mayor efecto para NO ser citado. Y Google exige
	 * que el dato legible por máquina COINCIDA con el visible — en /disponibles/
	 * un `strtotime()` + `wp_date()` encadenados produjeron un datetime corrido
	 * un día respecto del texto.
	 *
	 * @testdox The machine datetime and the human text must come from the same datum.
	 */
	public function test_datetime_and_visible_text_come_from_the_same_datum(): void {
		$html = $this->render();

		self::assertStringContainsString( '<time datetime="2026-08-04">', $html );
		self::assertStringContainsString( '4 de agosto de 2026', $html );

		// La fecha sale del catálogo (MAX(post_modified)) y NUNCA de "hoy": un
		// timestamp que se refresca solo es la forma más cara de mentir sobre
		// frescura, porque parece exactamente lo que el motor premia.
		$this->wpdb->row  = array( 'updated' => '2019-03-07 23:59:59' ) + self::ONE_PIECE;
		$this->nueva_peticion();
		$old              = $this->render();

		self::assertStringContainsString( '<time datetime="2019-03-07">', $old );
		self::assertStringContainsString( '7 de marzo de 2019', $old );
		self::assertStringNotContainsString( gmdate( 'Y-m-d' ), $old );

		// El día NO puede correrse por zona horaria: `post_modified` ya viene en
		// la del sitio, así que un valor de medianoche debe seguir siendo su
		// propio día en las dos representaciones.
		$this->wpdb->row  = array( 'updated' => '2026-01-01 00:00:00' ) + self::ONE_PIECE;
		$this->nueva_peticion();
		$midnight         = $this->render();

		self::assertStringContainsString( '<time datetime="2026-01-01">', $midnight );
		self::assertStringContainsString( '1 de enero de 2026', $midnight );
	}

	/**
	 * @testdox Should refuse a malformed modification date rather than guess it.
	 */
	public function test_refuses_a_malformed_date(): void {
		$this->wpdb->row = array( 'updated' => '0000-00-00 00:00:00' ) + self::ONE_PIECE;

		$html = $this->render();

		self::assertStringNotContainsString( '<time', $html );
		self::assertStringNotContainsString( 'Actualizado', $html );
	}

	/**
	 * @testdox Should query once per term and serve the rest from the transient.
	 */
	public function test_queries_once_and_then_serves_the_transient(): void {
		$this->render();
		$this->render();
		$this->render();

		self::assertCount( 1, $this->wpdb->queries, 'Sin transient la ficha pagaría la agregación en cada pageload.' );
		self::assertSame( self::ONE_PIECE['tomos'], (string) $this->transients['akb_series_facts_v5_378']['tomos'] );
		self::assertSame( 6 * HOUR_IN_SECONDS, $this->transients['akb_series_facts_v5_378::ttl'] );
	}

	/**
	 * ── Por qué el prefijo subió a v3 ───────────────────────────────────────
	 * `facts()` acepta cualquier array cacheado que traiga `tomos` y se lo pasa
	 * tal cual al render, así que una forma vieja sobreviviente NO puede tumbar
	 * la superficie más visitada del sitio con un `Undefined array key`. El
	 * prefijo se sube para que eso no ocurra, y las lecturas con `??` son la red
	 * por si ocurriera igual: lo que se pierde es la lista y la fila de autor, no
	 * la página.
	 *
	 * @testdox Should degrade, never fatal, if a cached entry from an older shape survives.
	 */
	public function test_degrades_when_a_legacy_shaped_entry_survives(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array(
				'tomos'       => 17,
				'instock'     => 8,
				'preventa'    => 5,
				'agotados'    => 4,
				'price_min'   => null,
				'price_max'   => null,
				'updated'     => '',
				'editoriales' => array(),
			),
		);

		$text = $this->text( $this->render() );

		self::assertStringContainsString( 'Agotado 4 tomos', $text );
		self::assertStringNotContainsString( ':', $text, 'Sin la clave nueva, el renglón es el de antes.' );
		self::assertSame( array(), $this->wpdb->queries, 'Y se sirve de la caché, sin re-consultar.' );
	}

	/**
	 * El transient se cachea POR TÉRMINO: una clave compartida haría que la
	 * primera serie visitada dictara los números de las otras 129.
	 *
	 * @testdox Should cache per term, never globally.
	 */
	public function test_caches_per_term(): void {
		$this->render();

		Functions\when( 'get_queried_object' )->justReturn( $this->series_term( 512 ) );
		$this->wpdb->row = array( 'tomos' => '3', 'instock' => '3', 'preventa' => '0' ) + self::ONE_PIECE;

		$text = $this->text( $this->render() );

		self::assertCount( 2, $this->wpdb->queries, 'Otro término, otra consulta.' );
		self::assertArrayHasKey( 'akb_series_facts_v5_512', $this->transients );
		self::assertArrayHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/**
	 * QA-BIZ-DIFF-3 (QA integral 2026-09-24): una landing pide los hechos seis
	 * veces —resumen, conteo de la barra, ficha y JSON-LD con la instancia
	 * registrada; meta de SEO y oferta del pack con instancias propias— y cada
	 * una releía la generación durable dos veces, más el transient: 12 SELECT
	 * por landing. La primera se verifica entera (2) y las otras cinco sólo
	 * comprueban que la generación no cambió (1 cada una, REV-QFB-2): 7.
	 *
	 * @testdox Una landing verifica la serie una vez y después sólo comprueba la generación, sin transient; una invalidación en la misma petición obliga a releer.
	 */
	public function test_una_landing_verifica_una_vez_y_despues_solo_comprueba_la_generacion(): void {
		// Petición que llena el transient (la primera tras una purga).
		$this->render();
		$memo = new \ReflectionProperty( SeriesFacts::class, 'verified_facts' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
		$this->wpdb->generation_queries = array();
		$this->wpdb->queries            = array();

		// La petición siguiente, con el transient caliente: los seis lectores.
		$registrada           = new SeriesFacts();
		$lecturas_transient   = 0;
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) use ( &$lecturas_transient ) {
				++$lecturas_transient;
				return $this->transients[ $key ] ?? false;
			}
		);
		ob_start();
		$registrada->render_summary();
		$registrada->result_count_extra( '' );
		$registrada->render();
		ob_end_clean();
		$registrada->catalog_facts( 378 );
		( new SeriesFacts() )->catalog_facts( 378 );
		$pack = ( new SeriesFacts() )->catalog_facts( 378 );

		self::assertCount( 7, $this->wpdb->generation_queries, 'La primera lectura se verifica (antes y después); las otras cinco sólo comprueban la generación.' );
		self::assertSame( 1, $lecturas_transient, 'Sólo la primera lee el transient.' );
		self::assertSame( array(), $this->wpdb->queries, 'Y ninguna vuelve a pedir el agregado.' );
		self::assertSame( self::ONE_PIECE['tomos'], (string) $pack['tomos'] );

		// Un guardado en la misma petición: lo verificado de esa serie deja de valer.
		$this->wpdb->col = array( '378' );
		$this->wpdb->row = array_merge( self::ONE_PIECE, array( 'tomos' => '3', 'instock' => '3', 'preventa' => '0', 'agotados' => '0' ) );
		$registrada->invalidate_product( 4321 );
		$antes = count( $this->wpdb->generation_queries );

		self::assertSame( 3, (int) ( new SeriesFacts() )->catalog_facts( 378 )['tomos'], 'Tras invalidar, los hechos nuevos.' );
		self::assertGreaterThan( $antes, count( $this->wpdb->generation_queries ), 'La lectura tras la invalidación se vuelve a verificar.' );
	}

	/**
	 * REV-QFB-2 (revisión del lote, 2026-09-24): la memoria por petición no
	 * puede saltarse la barrera entre procesos. Otra petición —una venta, una
	 * reposición— rota la generación durable de la serie directo en la base
	 * mientras esta landing sigue pintando, y en ESTE proceso no corre ninguna
	 * invalidación que descarte lo verificado. Con la memoria sola, la ficha
	 * bajo la rejilla y la oferta del pack pintaban los hechos de `wp_head`, y
	 * la página podía quedar en caché después de la purga de la otra petición.
	 *
	 * @testdox Una rotación de la generación hecha por otra petición a mitad del render obliga a releer: la memoria no tapa la barrera entre procesos.
	 */
	public function test_una_rotacion_de_otro_proceso_a_mitad_del_render_obliga_a_releer(): void {
		// Releer sobre la generación nueva queda verificado: la página puede ir a caché.
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )->never();
		$registrada = new SeriesFacts();
		self::assertSame( 107, (int) $registrada->catalog_facts( 378 )['tomos'], 'wp_head: la primera lectura, verificada.' );

		// Otra petición vende los últimos tomos y rota la generación en la base.
		$this->wpdb->row = array_merge( self::ONE_PIECE, array( 'tomos' => '3', 'instock' => '3', 'preventa' => '0', 'agotados' => '0' ) );
		$this->options['akb_series_facts_generation_378'] = 'rotada-por-otra-peticion';

		self::assertSame( 3, (int) $registrada->catalog_facts( 378 )['tomos'], 'La ficha bajo la rejilla pinta el catálogo nuevo.' );
		self::assertSame( 3, (int) ( new SeriesFacts() )->catalog_facts( 378 )['tomos'], 'Y la oferta del pack, con su propia instancia.' );
	}

	/**
	 * @testdox Una lectura que no queda verificada no se reusa: la siguiente vuelve a intentarlo.
	 */
	public function test_una_lectura_sin_verificar_no_se_reusa(): void {
		$rotante                  = new SeriesFactsRotatingGenerationWpdb();
		$rotante->row             = self::ONE_PIECE;
		$rotante->option_store    =& $this->options;
		$GLOBALS['wpdb']          = $rotante;
		$this->wpdb               = $rotante;

		( new SeriesFacts() )->catalog_facts( 378 );
		$tras_la_primera = $rotante->reads;
		( new SeriesFacts() )->catalog_facts( 378 );

		self::assertGreaterThan( $tras_la_primera, $rotante->reads, 'Sin verificar, cada lector vuelve a mirar la generación.' );
	}

	/**
	 * @testdox Should cache the empty result too, so a series without catalogue is not re-queried.
	 */
	public function test_caches_the_empty_result_too(): void {
		$this->wpdb->row = array( 'tomos' => '0' ) + self::ONE_PIECE;

		$this->render();
		$this->render();

		self::assertCount( 1, $this->wpdb->queries );
	}

	/**
	 * El `LEFT JOIN` de editoriales MULTIPLICA las filas (un producto tiene
	 * varias relaciones de término). Con `SUM(...)` cada tomo se contaría una vez
	 * por término asociado y la ficha publicaría números inflados con toda
	 * naturalidad — verde en la suite y falso en pantalla.
	 *
	 * @testdox The aggregate must count distinct products, never sum multiplied rows.
	 */
	public function test_counts_distinct_products_over_the_multiplying_join(): void {
		$this->render();
		$sql = $this->wpdb->queries[0];

		self::assertStringContainsString( 'COUNT(DISTINCT p.ID)', $sql );
		self::assertStringNotContainsString( 'SUM(', $sql );
		self::assertSame(
			3,
			substr_count( $sql, 'COUNT(DISTINCT CASE WHEN' ),
			'Los tres estados de stock se cuentan con DISTINCT sobre el id.'
		);
	}

	/**
	 * @testdox Should read stock from the indexed lookup table with the canonical status literals.
	 */
	public function test_reads_stock_from_the_indexed_lookup(): void {
		$this->render();
		$sql = $this->wpdb->queries[0];

		self::assertStringContainsString( 'wp_wc_product_meta_lookup l ON l.product_id = p.ID', $sql );
		self::assertStringNotContainsString( '_stock_status', $sql, 'postmeta.meta_value no está indexado.' );
		foreach ( array( 'instock', 'onbackorder', 'outofstock' ) as $status ) {
			self::assertStringContainsString( "l.stock_status = '{$status}'", $sql );
		}
		self::assertStringContainsString( 'tr.term_taxonomy_id = 378', $sql );
		self::assertStringContainsString( "p.post_status = 'publish'", $sql );
	}

	/**
	 * Hallazgo de la revisión propia: sin este test, cambiar el módulo para
	 * consultar por producto (`get_the_terms` en un loop, o un `wc_get_products`)
	 * dejaría la suite entera en verde y metería N+1 en el archive más visitado
	 * del sitio.
	 *
	 * @testdox The module must not open any per-product read path.
	 */
	public function test_has_no_per_product_read_path(): void {
		$source = (string) file_get_contents(
			(string) ( new ReflectionClass( SeriesFacts::class ) )->getFileName()
		);

		// Sin comentarios: los docblocks de este módulo CITAN a `get_the_terms`
		// para explicar de dónde salió la taxonomía de editorial, y un match
		// sobre el archivo crudo estaría probando la prosa, no el código.
		$source = implode(
			'',
			array_map(
				static fn( $token ): string => is_array( $token )
					? ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : $token[1] )
					: (string) $token,
				token_get_all( $source )
			)
		);

		foreach ( array( 'wc_get_product', 'get_posts(', 'new \WP_Query', 'get_the_terms', 'wp_get_object_terms' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $source, 'La ficha se arma con UNA agregación, no producto a producto.' );
		}
		self::assertSame( 1, substr_count( $source, '->get_row(' ), 'Una sola lectura de base de datos en todo el módulo.' );
	}

	/**
	 * @testdox Should mark up the block as a description list, not a decorative table.
	 */
	public function test_marks_up_the_block_semantically(): void {
		$html = $this->render();

		self::assertStringContainsString( '<section class="akb-series-facts" aria-label=', $html );
		self::assertStringContainsString( '<dl class="akb-series-facts__list">', $html );
		self::assertStringContainsString( '<dt class="akb-series-facts__label">', $html );
		self::assertStringContainsString( '<dd class="akb-series-facts__value">', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertSame(
			substr_count( $html, '<dt' ),
			substr_count( $html, '<dd' ),
			'Cada término tiene exactamente una definición.'
		);
	}

	/**
	 * El nombre accesible de la región no puede prometer «catálogo» cuando puede
	 * abrir con la edición exacta y la obra original, datos que no son stock.
	 * Quien navega por regiones —rotor de VoiceOver, tecla D de NVDA— debe oír los
	 * tres alcances sin confundir el total anunciado con lo disponible en Akibara.
	 *
	 * @testdox Should name the region after the edition and original work, not after the catalogue.
	 */
	public function test_the_region_name_covers_both_kinds_of_datum(): void {
		$this->wpdb->row['tomos'] = '4';
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '8' );

		$html = $this->render();

		self::assertStringContainsString( 'aria-label="Datos de esta edición y de la obra original"', $html );
		self::assertStringNotContainsString( 'aria-label="Datos del catálogo', $html );

		// La palabra no se pierde: sigue donde es cierta.
		self::assertStringContainsString( 'Catálogo actualizado', $html );
		self::assertStringContainsString( 'Edición Ivrea Argentina', $html );
		self::assertStringContainsString( '8 tomos previstos', $html );
		self::assertStringContainsString( 'Obra original', $html );
	}

	/**
	 * El bloque es una FICHA DE DATOS. `/nosotros/` afirma públicamente que las
	 * guías las escribe el dueño, así que un párrafo redactado acá convertiría
	 * esa afirmación en mentira.
	 *
	 * @testdox Should emit data only — no editorial prose.
	 */
	public function test_emits_data_only(): void {
		$html = $this->render();

		self::assertStringNotContainsString( '<p', $html );
		self::assertStringNotContainsString( '<h1', $html );
		self::assertStringNotContainsString( '<h2', $html );
	}

	/**
	 * ── Invalidación ────────────────────────────────────────────────────────
	 * El transient dura 6 h y es la ÚNICA capa que sobrevive a una purga de
	 * LiteSpeed, así que sin estos hooks el escenario es: se vende la última
	 * unidad de un tomo, la card de abajo pasa a "Agotado" y la ficha de arriba
	 * sigue contándolo como disponible durante horas. Los números seguirían
	 * cuadrando —nada parecería roto—, que es justo lo que hace peligroso el
	 * defecto.
	 *
	 * @testdox Should register the stock, content and term hooks that invalidate the cache.
	 */
	public function test_registers_every_invalidation_hook(): void {
		$module = new SeriesFacts();
		$module->register();

		foreach ( array(
			'woocommerce_product_set_stock_status' => 'invalidate_product',
			'woocommerce_product_set_stock'        => 'invalidate_product_object',
			'save_post_product'                    => 'invalidate_product',
			'before_delete_post'                   => 'invalidate_product',
			'added_post_meta'                      => 'invalidate_on_product_meta_changed',
			'updated_post_meta'                    => 'invalidate_on_product_meta_changed',
			'deleted_post_meta'                    => 'invalidate_on_product_meta_changed',
			'set_object_terms'                     => 'invalidate_on_term_change',
			'deleted_term_relationships'           => 'invalidate_on_deleted_term_relationships',
			'edited_product_brand'                  => 'invalidate_on_catalog_term_edited',
			'edited_pa_autor'                       => 'invalidate_on_catalog_term_edited',
		) as $hook => $method ) {
			self::assertNotFalse(
				Monkey\Actions\has( $hook, array( $module, $method ) ),
				sprintf( 'Sin %s la ficha puede contradecir a la grilla durante 6 h.', $hook )
			);
		}
	}

	/**
	 * El UCSS de LiteSpeed poda por lo que ve en el HTML que MUESTREA, y las tres
	 * clases de la ficha son condicionales: `__vols` sólo existe en las series con
	 * agotados, `__link` sólo cuando el término resolvió enlace y `__note` sólo
	 * cuando la frase de obra o edición trae un detalle. Si el muestreo cae en una
	 * serie que no los usa, las reglas se podan para las demás landings.
	 *
	 * @testdox Should protect its conditional classes from the UCSS tree-shaking.
	 */
	public function test_protects_its_conditional_classes_from_ucss(): void {
		$module = new SeriesFacts();
		$module->register();

		self::assertNotFalse(
			Monkey\Filters\has( 'litespeed_ucss_whitelist', array( $module, 'allowlist_ucss_selectors' ) )
		);

		$selectors = $module->allowlist_ucss_selectors( array( '.akb-badge--out_of_stock' ) );

		self::assertContains( '.akb-series-facts__vols', $selectors );
		self::assertContains( '.akb-series-facts__link', $selectors );
		self::assertContains( '.akb-series-facts__note', $selectors );
		self::assertContains( '.akb-badge--out_of_stock', $selectors, 'La lista de los otros módulos se conserva.' );
	}

	/**
	 * @testdox Should drop the cached facts of every series a product belongs to.
	 */
	public function test_invalidates_every_series_of_a_product(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array( 'tomos' => 107 ),
			'akb_series_facts_v5_512' => array( 'tomos' => 12 ),
			'akb_series_facts_v5_999' => array( 'tomos' => 4 ),
		);
		$this->wpdb->col  = array( '378', '512' );

		( new SeriesFacts() )->invalidate_product( 4321 );

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
		self::assertArrayNotHasKey( 'akb_series_facts_v5_512', $this->transients );
		self::assertArrayHasKey( 'akb_series_facts_v5_999', $this->transients, 'Una serie ajena al producto no se toca.' );

		// El ID viaja por placeholder y la taxonomía es la de series, no la de
		// marcas: un typo acá borraría transients de otra taxonomía o ninguno.
		self::assertStringContainsString( 'tr.object_id = 4321', $this->wpdb->queries[0] );
		self::assertStringContainsString( "tt.taxonomy = 'pa_serie'", $this->wpdb->queries[0] );
	}

	/** @testdox Should rotate all fact keys and purge globally when a product-to-series lookup fails. */
	public function test_database_failure_cannot_leave_a_cached_fact_snapshot_reusable(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array( 'tomos' => 1 ),
		);
		$this->wpdb->fail_col = true;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts invalidation unavailable' );
		Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();

		$module = new SeriesFacts();
		$module->invalidate_product( 4321 );

		self::assertSame( 'test-generation', $this->options['akb_series_facts_generation'] ?? '' );
		self::assertArrayHasKey( 'akb_series_facts_v5_378', $this->transients, 'La fila vieja puede expirar sola, pero ya no vuelve a ser direccionable.' );

		$this->wpdb->fail_col = false;
		ob_start();
		$module->render();
		ob_end_clean();

		self::assertArrayHasKey( 'akb_series_facts_v5_test-generation_378', $this->transients );
	}

	/** @testdox Should persist the emergency epoch even when update_option reports failure. */
	public function test_database_failure_uses_a_durable_option_fallback_across_requests(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array( 'tomos' => 1 ),
		);
		$this->wpdb->fail_col       = true;
		$this->option_updates_fail  = true;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts invalidation unavailable' );
		Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();

		( new SeriesFacts() )->invalidate_product( 4321 );

		self::assertSame(
			'test-generation',
			$this->options['akb_series_facts_generation'] ?? '',
			'El fallback SQL debe dejar una barrera que sobreviva al objeto actual.'
		);

		$this->wpdb->fail_col      = false;
		$this->option_updates_fail = false;
		ob_start();
		( new SeriesFacts() )->render();
		ob_end_clean();

		self::assertArrayHasKey(
			'akb_series_facts_v5_test-generation_378',
			$this->transients,
			'Una instancia nueva no puede volver a direccionar la clave legacy.'
		);
	}

	/** @testdox Should never treat a failed generation read as an absent epoch. */
	public function test_generation_database_error_cannot_reopen_an_old_transient_key(): void {
		$this->options = array(
			'akb_series_facts_generation'     => 'global-live',
			'akb_series_facts_generation_378' => 'series-live',
		);
		$this->transients = array(
			'akb_series_facts_v5_global-live_series-live_378' => array( 'tomos' => 1 ),
		);
		$failing_wpdb = new SeriesFactsGenerationReadFailureWpdb();
		$failing_wpdb->row = self::ONE_PIECE;
		$failing_wpdb->option_store =& $this->options;
		$this->wpdb = $failing_wpdb;
		$GLOBALS['wpdb'] = $failing_wpdb;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts generation unavailable' );

		$html = $this->render();

		self::assertStringContainsString( '31 tomos', $html, 'Se consulta el dato fresco y no se sirve el tomo cacheado.' );
		self::assertSame(
			array( 'akb_series_facts_v5_global-live_series-live_378' ),
			array_keys( $this->transients ),
			'La respuesta degradada no escribe ninguna generación ni transient nuevo.'
		);
		self::assertStringContainsString( 'SELECT option_name, option_value', $failing_wpdb->queries[0] ?? '' );
	}

	/** @testdox Should fail safely when the product lookup database API is unavailable. */
	public function test_missing_identifier_database_api_uses_the_global_fallback(): void {
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts invalidation unavailable' );
		Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		$GLOBALS['wpdb'] = new \stdClass();

		( new SeriesFacts() )->invalidate_product( 4321 );

		self::assertSame( 'test-generation', $this->options['akb_series_facts_generation'] ?? '' );
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/** @testdox Should retry under the new epoch when invalidation lands during cache fill. */
	public function test_cache_fill_cannot_republish_a_snapshot_invalidated_mid_write(): void {
		$old_row          = self::ONE_PIECE;
		$old_row['tomos'] = '1';
		$new_row          = self::ONE_PIECE;
		$new_row['tomos'] = '2';
		$this->wpdb->row  = $old_row;
		$this->options['akb_series_facts_generation_378'] = 'series-old';
		$interleaved = false;

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ) use ( &$interleaved, $new_row ): bool {
				if ( ! $interleaved ) {
					$interleaved    = true;
					$this->wpdb->row = $new_row;
					// Otro proceso actualiza MySQL, pero no puede tocar la caché PHP
					// privada de este request. El recheck debe expulsarla por sí mismo.
					$this->options['akb_series_facts_generation_378'] = 'series-new';
				}
				$this->transients[ $key ] = $value;
				$this->transients[ $key . '::ttl' ] = $ttl;
				return true;
			}
		);

		ob_start();
		( new SeriesFacts() )->render();
		ob_end_clean();

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
		self::assertArrayNotHasKey( 'akb_series_facts_v5_series-old_378', $this->transients );
		self::assertSame(
			2,
			$this->transients['akb_series_facts_v5_series-new_378']['tomos'] ?? 0,
			'El único snapshot direccionable debe salir de la consulta repetida.'
		);
		$aggregate_queries = array_filter(
			$this->wpdb->queries,
			static fn( string $query ): bool => str_contains( $query, 'COUNT(DISTINCT' )
		);
		self::assertCount( 2, $aggregate_queries );
	}

	/** @testdox Should rotate a touched series at mutation start and request shutdown. */
	public function test_series_epoch_has_an_immediate_and_a_final_generation(): void {
		$this->wpdb->col          = array( '378:12' );
		$this->generation_tokens  = array( 'series-start', 'series-final' );
		Monkey\Actions\expectDone( 'litespeed_purge' )->twice()->with( 'T.12' );

		$module = new SeriesFacts();
		$module->invalidate_product( 4321 );
		self::assertSame( 'series-start', $this->options['akb_series_facts_generation_378'] ?? '' );

		$module->finalize_cache_invalidations();

		self::assertSame( 'series-final', $this->options['akb_series_facts_generation_378'] ?? '' );
	}

	/**
	 * Un tomo que cambia de serie altera el conteo de las DOS, y los dos juegos
	 * de `term_taxonomy_id` llegan en los propios argumentos del hook.
	 *
	 * @testdox Should invalidate both the new and the previous series on a reassignment.
	 */
	public function test_invalidates_both_sides_of_a_series_reassignment(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array( 'tomos' => 107 ),
			'akb_series_facts_v5_512' => array( 'tomos' => 12 ),
		);
		$this->wpdb->col  = array( '512:12', '378:34' );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->with( \Mockery::on( static fn( string $tag ): bool => in_array( $tag, array( 'T.12', 'T.34' ), true ) ) );

		( new SeriesFacts() )->invalidate_on_term_change( 4321, array(), array( 512 ), 'pa_serie', false, array( 378 ) );

		self::assertSame( array(), $this->transients );
		self::assertCount( 1, $this->wpdb->queries, 'Los dos lados se resuelven a term_id en un solo lote.' );
		self::assertStringContainsString( 'term_taxonomy_id IN (512, 378)', $this->wpdb->queries[0] );
	}

	/**
	 * @testdox Should invalidate current series when direct API calls change editorial or author.
	 */
	public function test_invalidates_direct_editorial_and_author_changes(): void {
		$this->wpdb->col = array( '378' );

		foreach ( array( 'product_brand', 'pa_autor' ) as $taxonomy ) {
			$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
			( new SeriesFacts() )->invalidate_on_term_change( 4321, array(), array(), $taxonomy, false, array() );
			self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
		}
	}

	/** @testdox Should purge the series page tag when a direct editorial change bypasses post saving. */
	public function test_direct_editorial_change_purges_the_series_landing(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
		$this->wpdb->col  = array( '378:12' );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->once()
			->with( 'T.12' );

		( new SeriesFacts() )->invalidate_on_term_change( 4321, array(), array(), 'product_brand', false, array() );

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/** @testdox Should invalidate facts and HTML after a direct product-cover mutation. */
	public function test_direct_thumbnail_change_purges_the_series_landing(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
		$this->wpdb->col  = array( '378:12' );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->once()
			->with( 'T.12' );

		( new SeriesFacts() )->invalidate_on_product_meta_changed( 99, 4321, '_thumbnail_id' );

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/**
	 * La marca de variante decide los conteos y la lista «Agotado: …» de esta
	 * misma consulta, y se pone por WP-CLI o por importación —caminos que no
	 * disparan `save_post_product`—. Sin este hook, marcar o desmarcar un tomo
	 * dejaba la landing sirviendo la foto anterior seis horas y QA midiendo un
	 * falso «la palanca no hace nada».
	 *
	 * @testdox Should invalidate facts and HTML after a direct volume-variant mutation.
	 */
	public function test_direct_variant_mark_purges_the_series_landing(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
		$this->wpdb->col  = array( '378:12' );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->once()
			->with( 'T.12' );

		( new SeriesFacts() )->invalidate_on_product_meta_changed( 99, 4321, VolumeVariant::META_KEY );

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/** @testdox Should ignore product metadata that does not change the series card. */
	public function test_ignores_unrelated_product_meta_changes(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );

		( new SeriesFacts() )->invalidate_on_product_meta_changed( 99, 4321, '_price' );

		self::assertArrayHasKey( 'akb_series_facts_v5_378', $this->transients );
		self::assertSame( array(), $this->wpdb->queries );
	}

	/** @testdox Should ignore relationship changes that do not feed the facts aggregate. */
	public function test_ignores_unrelated_term_changes(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );

		( new SeriesFacts() )->invalidate_on_term_change( 4321, array(), array(), 'pa_genero', false, array() );

		self::assertArrayHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/** @testdox Should invalidate current series when product catalogue visibility changes. */
	public function test_invalidates_series_when_catalog_visibility_changes(): void {
		$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
		$this->wpdb->col  = array( '378' );

		( new SeriesFacts() )->invalidate_on_term_change( 4321, array(), array(), 'product_visibility' );

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
	}

	/** @testdox Should also invalidate direct removals of editorial and author relationships. */
	public function test_invalidates_direct_editorial_and_author_removals(): void {
		$this->wpdb->col = array( '378' );

		foreach ( array( 'product_brand', 'pa_autor' ) as $taxonomy ) {
			$this->transients = array( 'akb_series_facts_v5_378' => array( 'tomos' => 107 ) );
			( new SeriesFacts() )->invalidate_on_deleted_term_relationships( 4321, array( 99 ), $taxonomy );
			self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
		}
	}

	/** @testdox Should invalidate removed series relationships without querying a deleted product. */
	public function test_invalidates_directly_removed_series_relationships(): void {
		$this->transients = array(
			'akb_series_facts_v5_378' => array( 'tomos' => 107 ),
			'akb_series_facts_v5_512' => array( 'tomos' => 12 ),
		);
		$this->wpdb->col  = array( '378:12' );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->with( 'T.12' );

		$module = new SeriesFacts();
		$module->invalidate_on_deleted_term_relationships( 4321, array( 378 ), 'pa_serie' );
		$module->finalize_cache_invalidations();

		self::assertArrayNotHasKey( 'akb_series_facts_v5_378', $this->transients );
		self::assertArrayHasKey( 'akb_series_facts_v5_512', $this->transients );
		self::assertCount( 1, $this->wpdb->queries );
		self::assertStringContainsString( 'term_taxonomy_id IN (378)', $this->wpdb->queries[0] );
	}

	/** @testdox Should invalidate every series when an author or editorial term is renamed. */
	public function test_invalidates_series_when_an_editorial_identity_is_renamed(): void {
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->times( 4 )
			->with( \Mockery::on( static fn( string $tag ): bool => in_array( $tag, array( 'T.12', 'T.34' ), true ) ) );

		foreach ( array( 'product_brand', 'pa_autor' ) as $taxonomy ) {
			$this->transients   = array(
				'akb_series_facts_v5_378' => array( 'tomos' => 107 ),
				'akb_series_facts_v5_512' => array( 'tomos' => 12 ),
			);
			$this->wpdb->queries = array();
			$this->wpdb->col     = array( '378:12', '512:34' );

			( new SeriesFacts() )->invalidate_on_catalog_term_edited( 230, 991 );

			self::assertSame( array(), $this->transients, $taxonomy . ' must invalidate every dependent series.' );
			self::assertCount( 1, $this->wpdb->queries, 'La identidad se resuelve en un solo lote.' );
			self::assertStringContainsString( 'changed_relationship.term_taxonomy_id = 991', $this->wpdb->queries[0] );
		}
	}

	/**
	 * Con `COUNT()` y sin `GROUP BY`, MySQL devuelve SIEMPRE una fila: un null
	 * sólo puede ser un error (lookup ausente tras un update de WooCommerce,
	 * deadlock, conexión caída). Cachearlo como "esta serie no tiene catálogo"
	 * borraría la ficha durante 6 h por un hipo de un solo request.
	 *
	 * @testdox Should never cache a database error as an empty series.
	 */
	public function test_does_not_cache_a_database_error(): void {
		$this->wpdb->row = null;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts unavailable' );

		self::assertSame( '', $this->render() );
		self::assertSame( array(), $this->transients, 'Un error no es un hecho: no se cachea.' );

		// Y el siguiente request vuelve a intentarlo en vez de servir el vacío.
		$this->wpdb->row = self::ONE_PIECE;
		self::assertStringContainsString( 'Disponible 31 tomos', $this->text( $this->render() ) );
	}
	/**
	 * @testdox Pintar la ficha no puede tirar el índice de opciones ausentes de la petición.
	 *
	 * El snapshot de generación se lee con SQL crudo justamente para distinguir
	 * «la opción no existe» de «la consulta falló», así que nunca consulta el
	 * object cache. Pese a eso, cada refresco lo evictaba —y arrastraba
	 * `wp_cache_delete( 'notoptions', 'options' )`, que borra el índice de
	 * opciones inexistentes de TODA la petición—. A partir de ahí cualquier
	 * `get_option()` de una opción ausente, y entre core, WooCommerce y los
	 * plugins hay decenas, volvía a golpear la base. Y no ocurría una vez:
	 * `facts()` refresca hasta cuatro veces por serie.
	 */
	public function test_rendering_never_evicts_the_shared_notoptions_index(): void {
		self::assertNotSame( '', $this->render(), 'El test necesita que la ficha se pinte de verdad.' );

		self::assertNotContains(
			'notoptions',
			$this->cache_deletes,
			'Un módulo no puede invalidar la caché de opciones ausentes que comparte con todo el sitio.'
		);
	}

	// -------------------------------------------------------------------------
	// Resumen de una línea sobre la rejilla (UM-1)
	// -------------------------------------------------------------------------

	/** @param callable|null $gate Predicado de override de precio. */
	private function render_summary( ?callable $gate = null ): string {
		ob_start();
		( new SeriesFacts( $gate ) )->render_summary();

		return (string) ob_get_clean();
	}

	/**
	 * La pregunta que trae la búsqueda —«cuántos tomos tiene»— se contesta arriba
	 * aunque la ficha haya bajado: con la frase de la EDICIÓN y su rótulo, para
	 * que «en publicación» no se lea como estado de la obra.
	 *
	 * @testdox The summary answers with the edition phrase under its scope label and links to the full sheet.
	 */
	public function test_summary_answers_with_the_edition_phrase_and_links_to_the_sheet(): void {
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '38' );

		$html = $this->render_summary();
		$text = $this->text( $html );

		self::assertStringContainsString( 'Edición Ivrea Argentina', $text );
		self::assertStringContainsString( 'En publicación, 38 tomos previstos', $text );
		self::assertStringContainsString( 'href="#ficha-de-la-serie"', $html );
		self::assertStringContainsString( 'Ver ficha completa', $text );
		self::assertStringNotContainsString( 'Obra original', $text, 'Una línea: sólo la primera frase; la de la obra queda en la ficha.' );
	}

	/**
	 * @testdox The summary and the full sheet state the very same publication phrase.
	 */
	public function test_summary_and_sheet_share_the_publication_phrase(): void {
		$this->publication( PublicationStatus::COMPLETE, '', PublicationStatus::ONGOING, '38' );

		$summary = $this->text( $this->render_summary() );
		$sheet   = $this->text( $this->render() );

		foreach ( array( 'Edición Ivrea Argentina', 'En publicación', '38 tomos previstos' ) as $fragment ) {
			self::assertStringContainsString( $fragment, $summary );
			self::assertStringContainsString( $fragment, $sheet );
		}
	}

	/**
	 * @testdox The summary falls back to the price range when no publication status was loaded.
	 */
	public function test_summary_falls_back_to_the_price_range(): void {
		$text = $this->text( $this->render_summary() );

		self::assertStringContainsString( 'Precios', $text );
		self::assertStringContainsString( '$9.500', $text );
		self::assertStringContainsString( '$9.990', $text );
	}

	/**
	 * Los conteos por estado ya los dicen los chips de «Arma tu pack», pegados a
	 * la rejilla: repetirlos arriba es gastar la línea en lo que está dos líneas
	 * más abajo.
	 *
	 * @testdox The summary never repeats the stock counts the availability chips already show.
	 */
	public function test_summary_does_not_repeat_the_stock_counts(): void {
		$this->publication( '', '', PublicationStatus::ONGOING, '38' );

		$text = $this->text( $this->render_summary() );

		self::assertStringNotContainsString( 'Disponible', $text );
		self::assertStringNotContainsString( '31 tomos', $text );
	}

	/**
	 * @testdox The summary keeps only the link when there is neither a status nor a publishable price.
	 */
	public function test_summary_keeps_only_the_link_without_a_datum(): void {
		$html = $this->render_summary( static fn (): bool => true );

		self::assertStringNotContainsString( '<dl', $html, 'Sin dato no se inventa uno.' );
		self::assertStringContainsString( 'href="#ficha-de-la-serie"', $html );
	}

	/**
	 * @testdox Should print no summary outside a series archive or for a series without products.
	 */
	public function test_summary_prints_nothing_where_the_sheet_prints_nothing(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		self::assertSame( '', $this->render_summary() );

		Functions\when( 'is_tax' )->justReturn( true );
		$this->wpdb->row = array_merge( self::ONE_PIECE, array( 'tomos' => '0' ) );
		self::assertSame( '', $this->render_summary(), 'Sin ficha no hay a dónde enlazar.' );
	}

	/**
	 * @testdox The summary link is the owner's copy from Textos, and an empty one falls back to the factory text.
	 */
	public function test_summary_link_is_the_owners_copy(): void {
		$this->options[ SeriesFacts::OPTION_SUMMARY_LINK ] = 'Todos los datos de la serie';
		self::assertStringContainsString( 'Todos los datos de la serie', $this->render_summary() );

		$this->options[ SeriesFacts::OPTION_SUMMARY_LINK ] = '   ';
		$this->option_cache = array();
		self::assertStringContainsString( SeriesFacts::DEFAULT_SUMMARY_LINK, $this->render_summary() );
	}

	/**
	 * Consulta principal de una landing con `$total` páginas, en la `$paged`.
	 *
	 * El atajo NO debe leerla (ver `result_count_extra()`); se monta para probar
	 * justamente eso: que la página y el orden en curso no cambian nada.
	 */
	private function main_query_with_pages( int $total, int $paged ): void {
		$query                = new class( array( 'paged' => $paged ) ) extends \WP_Query {
			/** @var int Páginas de la consulta, como en Core. */
			public $max_num_pages = 0;
		};
		$query->max_num_pages = $total;
		$GLOBALS['wp_query']  = $query;
	}

	/** Lo que aporta el módulo a la línea del total del buscador. */
	private function count_extra( $previous = '' ): string {
		return ( new SeriesFacts() )->result_count_extra( $previous );
	}

	/**
	 * Revisión B3-2-R2: con 48 tomos por página, en 109 de 110 series el tomo más
	 * nuevo quedaba al fondo de una sola página larga («Jujutsu Kaisen 30» a
	 * 8.121 px en 390x844) y el atajo de B3 sólo salía en la serie que pagina.
	 *
	 * @testdox The shortcut links to the page of the newest volume and names its number.
	 */
	public function test_latest_volume_shortcut_links_to_the_newest_volume(): void {
		self::assertSame(
			'<a class="akb-series-latest" href="https://akibara.cl/producto-5107/">Último tomo: 107&nbsp;<span aria-hidden="true">→</span></a>',
			$this->count_extra()
		);
	}

	/**
	 * @testdox The shortcut is there on a series that fits in a single page.
	 */
	public function test_latest_volume_shortcut_is_there_on_a_one_page_series(): void {
		$this->main_query_with_pages( 1, 1 );

		self::assertStringContainsString( 'href="https://akibara.cl/producto-5107/"', $this->count_extra() );
	}

	/**
	 * Revisión B3-2-R1 y B3-2-R3: el atajo de B3 enlazaba la última PÁGINA, así
	 * que con «Más recientes» llevaba a los tomos más antiguos y, tras paginar
	 * por PJAX, apuntaba a la página en la que ya se estaba. El tomo más nuevo es
	 * un hecho de la serie: la misma respuesta en cualquier página y orden, y sin
	 * pedirle a WordPress enlaces de paginación.
	 *
	 * @testdox The shortcut is the same on every page, under every sort order and filter.
	 */
	public function test_latest_volume_shortcut_ignores_page_order_and_filters(): void {
		Functions\expect( 'get_pagenum_link' )->never();
		$plain = $this->count_extra();

		foreach ( array( 'date', 'menu_order', 'price', 'price-desc', 'popularity', 'title' ) as $orderby ) {
			foreach ( array( 1, 2, 3 ) as $paged ) {
				$_GET = array( 'orderby' => $orderby, '_disponibilidad' => 'instock' );
				$this->main_query_with_pages( 3, $paged );
				self::assertSame( $plain, $this->count_extra(), "orderby={$orderby}, página {$paged}" );
			}
		}
	}

	/**
	 * Revisión B3-2-R3: el resumen está fuera de la lista que el PJAX reemplaza,
	 * así que sólo puede llevar lo que vale igual en todas las páginas.
	 *
	 * @testdox The summary above the grid is identical on every page and carries no page link.
	 */
	public function test_summary_is_identical_on_every_page(): void {
		Functions\expect( 'get_pagenum_link' )->never();
		$this->main_query_with_pages( 3, 1 );
		$first = $this->render_summary();

		$this->main_query_with_pages( 3, 3 );
		$_GET = array( 'orderby' => 'date' );
		self::assertSame( $first, $this->render_summary() );
		self::assertStringNotContainsString( 'page/', $first );
		self::assertStringNotContainsString( 'akb-series-latest', $first, 'El atajo va en la línea del total, no aquí.' );
	}

	/**
	 * @testdox There is no shortcut on a one-volume series, when the last title has no volume number, or outside a series.
	 */
	public function test_no_latest_volume_shortcut_when_there_is_nothing_to_name(): void {
		$this->wpdb->row = array_merge( self::ONE_PIECE, array( 'tomos' => '1' ) );
		self::assertSame( '', $this->count_extra(), 'Con un tomo, el «último» es la única tarjeta.' );

		foreach ( array( "Uzumaki (Integral)\x1f77", "Ghost in the Shell 1.5\x1f9", "One Piece 107\x1f0", 'One Piece 107', null ) as $pair ) {
			$this->nueva_peticion();
			$this->wpdb->row  = array_merge( self::ONE_PIECE, array( 'ultimo_tomo' => $pair ) );
			self::assertSame( '', $this->count_extra(), 'Sin número de tomo inequívoco no se inventa uno: ' . var_export( $pair, true ) );
		}

		Functions\when( 'is_tax' )->justReturn( false );
		self::assertSame( '<b>otro</b>', $this->count_extra( '<b>otro</b>' ), 'Fuera de una serie, lo de los demás pasa intacto.' );
	}

	/**
	 * @testdox The shortcut is appended to what other modules already put next to the total.
	 */
	public function test_latest_volume_shortcut_keeps_what_others_added(): void {
		$html = $this->count_extra( '<span class="otro">x</span>' );

		self::assertStringStartsWith( '<span class="otro">x</span><a class="akb-series-latest"', $html );
		self::assertStringStartsWith( '<a class="akb-series-latest"', $this->count_extra( null ), 'Un valor raro del filtro no se propaga.' );
	}

	/**
	 * El texto es del dueño (Textos → Landing de serie). El número entra por el
	 * marcador y nunca por `sprintf`: un «%» suelto sería un fatal en todas las
	 * landings.
	 *
	 * @testdox The shortcut text is the owner's copy, with the volume number in its marker.
	 */
	public function test_latest_volume_shortcut_is_the_owners_copy(): void {
		$this->options[ SeriesFacts::OPTION_LATEST_VOLUME ] = 'Ir al tomo {tomo}';
		self::assertStringContainsString( '>Ir al tomo 107&nbsp;<span', $this->count_extra() );

		$this->options[ SeriesFacts::OPTION_LATEST_VOLUME ] = 'Lo más nuevo';
		$this->option_cache = array();
		self::assertStringContainsString( '>Lo más nuevo&nbsp;<span', $this->count_extra(), 'Sin marcador sale tal cual.' );

		$this->options[ SeriesFacts::OPTION_LATEST_VOLUME ] = '100% nuevo: {tomo} %s %d';
		$this->option_cache = array();
		self::assertStringContainsString( '>100% nuevo: 107 %s %d&nbsp;<span', $this->count_extra(), 'Un «%» no es un formato.' );

		$this->options[ SeriesFacts::OPTION_LATEST_VOLUME ] = '   ';
		$this->option_cache = array();
		self::assertStringContainsString( '>Último tomo: 107&nbsp;<span', $this->count_extra(), 'Vacío vuelve al de fábrica.' );
	}

	/**
	 * @testdox Should hook the shortcut into the search plugin's result-count line.
	 */
	public function test_hooks_the_shortcut_into_the_result_count_line(): void {
		$module = new SeriesFacts();
		$module->register();

		self::assertNotFalse( Monkey\Filters\has( 'akb_search_result_count_extra', array( $module, 'result_count_extra' ) ) );
		self::assertContains( '.akb-series-latest', $module->allowlist_ucss_selectors( array() ), 'Clase condicional: UCSS no la poda.' );
	}

	/**
	 * `ultimo_tomo` sale del MISMO agregado, con el MISMO filtro de «tomo normal»
	 * que los conteos: una edición especial del tomo más alto no es «el último
	 * tomo». Y como `prepare()` sustituye por orden textual, el grupo nuevo de
	 * placeholders no puede correr los de más abajo.
	 *
	 * @testdox The newest volume comes from the same aggregate, with the normal-volume filter and aligned placeholders.
	 */
	public function test_latest_volume_comes_from_the_same_aggregate(): void {
		$this->count_extra();
		$sql = $this->wpdb->queries[0];

		$tomo_normal = "TRIM( COALESCE( vm.meta_value, '' ) ) NOT IN ( '"
			. implode( "', '", VolumeVariant::variant_kinds() )
			. "' )";

		self::assertStringContainsString( 'CASE WHEN ' . $tomo_normal . " THEN CONCAT(p.post_title, '\x1f', p.ID) END", $sql );
		self::assertStringContainsString( ') AS ultimo_tomo', $sql );
		self::assertStringContainsString( "vm.meta_key = '" . VolumeVariant::META_KEY . "'", $sql, 'El reparto de placeholders sigue alineado.' );
		self::assertStringContainsString( 'tr.term_taxonomy_id = 378', $sql );
		self::assertSame( 1, count( $this->wpdb->queries ), 'Una consulta, la de siempre.' );
		self::assertSame( array( 'id' => 5107, 'numero' => 107 ), $this->transients['akb_series_facts_v5_378']['ultimo_tomo'] ?? null );
	}

	/**
	 * Revisión B3-R6: el texto del enlace lo escribe el dueño y el CSS ya no le
	 * pone `nowrap`; la flecha se queda con la última palabra por un espacio duro.
	 *
	 * @testdox The arrow stays glued to the owner's link text with a non-breaking space.
	 */
	public function test_arrow_is_glued_with_a_non_breaking_space(): void {
		self::assertStringContainsString( SeriesFacts::DEFAULT_SUMMARY_LINK . '&nbsp;<span aria-hidden="true">↓</span>', $this->render_summary() );
	}

	/**
	 * @testdox The full sheet carries the anchor the summary links to.
	 */
	public function test_sheet_carries_the_summary_anchor(): void {
		$html = $this->render();

		self::assertStringContainsString( '<section class="akb-series-facts"', $html, 'El tema busca esta clase exacta.' );
		self::assertStringContainsString( 'id="' . SeriesFacts::SHEET_ID . '"', $html );
	}

	// -------------------------------------------------------------------------
	// Page cache tras llenar el transient (PC-2)
	// -------------------------------------------------------------------------

	/**
	 * Medido en el espejo el 2026-09-23: «series facts cache fill» era la única
	 * razón de no-cache de /serie/haikyuu/ y /serie/one-piece/, y se disparaba en
	 * la primera visita tras cualquier cambio de stock de la serie. Un llenado
	 * verificado contra la generación durable vale lo mismo que una lectura del
	 * transient y no puede dejar la landing sin page cache.
	 *
	 * @testdox A cache fill verified against the durable generation keeps the page cacheable.
	 */
	public function test_a_verified_cache_fill_keeps_the_page_cacheable(): void {
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )->never();

		self::assertNotSame( '', $this->render() );
		self::assertSame( 107, $this->transients['akb_series_facts_v5_378']['tomos'] ?? null, 'El llenado quedó escrito.' );
	}

	/**
	 * @testdox A fill that invalidations keep overtaking is served but never sealed in the page cache.
	 */
	public function test_an_unverified_cache_fill_is_never_sealed(): void {
		$rotating               = new SeriesFactsRotatingGenerationWpdb();
		$rotating->row          = self::ONE_PIECE;
		$rotating->option_store =& $this->options;
		$GLOBALS['wpdb']        = $rotating;

		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts cache fill unverified' );

		self::assertStringContainsString( 'Disponible 31 tomos', $this->text( $this->render() ), 'Los hechos recién leídos se pintan igual.' );
		self::assertSame( array(), $this->transients, 'Un llenado sin verificar no se deja como snapshot.' );
	}

	/**
	 * @testdox Two cached reads overtaken by invalidations print nothing and never seal the page.
	 */
	public function test_two_overtaken_cached_reads_print_nothing_and_are_not_sealed(): void {
		$rotating               = new SeriesFactsRotatingGenerationWpdb();
		$rotating->row          = self::ONE_PIECE;
		$rotating->option_store =& $this->options;
		$GLOBALS['wpdb']        = $rotating;

		// Lecturas 1 y 3 abren cada intento; la 2 y la 4 son el recheck, que ya
		// ve otra generación.
		$this->transients['akb_series_facts_v5_gen-1_378'] = array( 'tomos' => 5 );
		$this->transients['akb_series_facts_v5_gen-3_378'] = array( 'tomos' => 6 );

		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series facts unavailable' );

		self::assertSame( '', $this->render() );
	}
}
