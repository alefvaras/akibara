<?php
/**
 * Pruebas del render, el cacheo y la invalidación del directorio de series.
 *
 * ── Por qué NO hay un doble del índice ──────────────────────────────────────
 * El shortcode se prueba contra `SeriesIndex` y `SeriesCatalogEligibility`
 * reales, doblando solamente WordPress y la base por debajo. Un doble del índice
 * dejaría sin cubrir justo lo importante: que la regla de catálogo llegue al
 * HTML. El cache se observa contando llamadas a `get_terms()`.
 *
 * Los stubs se declaran TODOS en `setUp()` con valores deterministas, aunque un
 * test concreto no los use: Brain Monkey deja el símbolo definido después del
 * `tearDown()`, así que una función mockeada por un test le llega vacía al
 * siguiente. Acá eso significaría que `get_option()` devolviera null y los hooks
 * de stock se registraran o no según la permutación — verde en orden natural,
 * rojo con `--order-by=random`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesDirectory;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesDirectory\Shortcode;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

final class ShortcodeEligibilityWpdb {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	public bool $fail = false;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return list<array<string,mixed>>|null */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		unset( $query, $output );
		if ( $this->fail ) {
			return null;
		}
		return $this->rows;
	}
}

final class ShortcodeTest extends TestCase {

	private const CACHE_KEY = 'akb_series_directory_v4';

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var list<WP_Term> */
	private array $terms = array();

	/** @var array<int, mixed> */
	private array $robots = array();

	/** @var array<int, string> Estado de la edición por serie (UC-14). */
	private array $estados = array();

	/** @var array<int,int> */
	private array $catalog_counts = array();

	private ShortcodeEligibilityWpdb $eligibility_database;

	/** Veces que se consultó la taxonomía. */
	private int $term_queries = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients   = array();
		$this->terms        = array();
		$this->robots       = array();
		$this->catalog_counts = array();
		$this->eligibility_database = new ShortcodeEligibilityWpdb();
		$this->term_queries = 0;

		// Catálogo de muestra con los tres casos reales que el orden tiene que
		// resolver: título que empieza por dígito, serie de un solo tomo y
		// título con acento.
		$this->term( 1, '20th-century-boys', '20th Century Boys', 11 );
		$this->term( 2, 'akira', 'Akira', 6 );
		$this->term( 3, 'ayakashi-triangle', 'Ayakashi Triangle', 1 );

		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		SeriesHubUrl::reset();

		Functions\when( 'get_terms' )->alias(
			function ( array $args ) {
				++$this->term_queries;

				$terms = $this->terms;
				if ( ! empty( $args['hide_empty'] ) ) {
					$terms = array_values( array_filter( $terms, static fn( WP_Term $t ): bool => $t->count > 0 ) );
				}

				return $terms;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = false ) => PublicationStatus::META_EDITION_STATE === $key
				? ( $this->estados[ $term_id ] ?? '' )
				: ( $this->robots[ $term_id ] ?? '' )
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'https://akibara.cl/serie/' . $term->slug . '/'
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				$this->transients[ $key ]           = $value;
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
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function term( int $id, string $slug, string $name, int $count ): void {
		$term           = new WP_Term( $id, $slug, $name );
		$term->taxonomy = 'pa_serie';
		$term->count    = $count;
		$this->terms[]  = $term;
	}

	private function shortcode( ?SeriesCatalogEligibility $eligibility = null ): Shortcode {
		if ( null === $eligibility ) {
			$this->eligibility_database->rows = array_map(
				fn( WP_Term $term ): array => array(
					'term_taxonomy_id' => $term->term_taxonomy_id,
					'product_count'     => $this->catalog_counts[ $term->term_id ] ?? $term->count,
					'volume_count'      => $this->catalog_counts[ $term->term_id ] ?? $term->count,
				),
				$this->terms
			);
			$eligibility = new SeriesCatalogEligibility( $this->eligibility_database );
		}

		return new Shortcode( new SeriesIndex( $eligibility ) );
	}

	/**
	 * @testdox Should register the shortcode tag.
	 */
	public function test_registers_the_shortcode(): void {
		// Se captura con `when()` y no con `expect()`: el `setUp()` ya declaró
		// esta función para que ningún otro test la reciba vacía, y una
		// expectativa de Mockery encima de una definición previa nunca llega a
		// ejecutarse. Redefinir la alias sí gana.
		$registered = array();
		Functions\when( 'add_shortcode' )->alias(
			function ( string $tag, $callback ) use ( &$registered ): bool {
				$registered[ $tag ] = $callback;
				return true;
			}
		);

		$shortcode = $this->shortcode();
		$shortcode->register();

		self::assertSame( 'akibara_series_directory', Shortcode::TAG );
		self::assertSame( array( $shortcode, 'render' ), $registered[ Shortcode::TAG ] ?? null );
	}

	/**
	 * @testdox Should print one linked entry per series with a catalog-scoped volume count.
	 */
	public function test_prints_one_linked_entry_per_series(): void {
		$html = $this->shortcode()->render();

		self::assertStringContainsString( 'href="https://akibara.cl/serie/akira/"', $html );
		self::assertStringContainsString( '>Akira<', $html );
		self::assertStringContainsString( '>6 tomos en catálogo<', $html );
		// Singular real: una serie de un tomo no puede decir «1 tomos».
		self::assertStringContainsString( '>1 tomo en catálogo<', $html );
		self::assertStringNotContainsString( '>1 tomos<', $html );
		self::assertStringNotContainsString( '>6 tomos<', $html, 'El conteo desnudo se confunde con el total de la edición.' );
	}

	/**
	 * El conjunto se decide en tiempo de ejecución: una serie sin productos y
	 * una en `noindex` no pueden llegar al HTML por ningún camino.
	 *
	 * @testdox Should never link an empty or noindex series from the rendered page.
	 */
	public function test_never_links_an_empty_or_noindex_series(): void {
		$this->term( 4, 'bleach', 'Bleach', 0 );
		$this->term( 5, 'pack-vinland-saga-123', 'Pack Vinland Saga 1+2+3', 1 );
		$this->robots[5] = array( 'noindex' );

		$html = $this->shortcode()->render();

		self::assertStringNotContainsString( 'bleach', $html );
		self::assertStringNotContainsString( 'pack-vinland-saga-123', $html );
		self::assertStringContainsString( '3 ediciones con tomos en catálogo', $html );
	}

	/** @testdox Should never link a series whose products are all hidden from the catalogue. */
	public function test_never_links_a_hidden_only_series(): void {
		$this->term( 6, 'tokyo-ghoul', 'Tokyo Ghoul', 14 );
		$this->catalog_counts[6] = 0;

		$html = $this->shortcode()->render();

		self::assertStringNotContainsString( '/serie/tokyo-ghoul/', $html );
		self::assertStringContainsString( '3 ediciones con tomos en catálogo', $html );
	}

	/**
	 * @testdox Should announce how many series the directory publishes.
	 */
	public function test_announces_the_total(): void {
		self::assertStringContainsString( '3 ediciones con tomos en catálogo', $this->shortcode()->render() );
	}

	/** @testdox Should use the singular edition unit for a one-entry directory. */
	public function test_announces_one_edition_in_the_singular(): void {
		$this->terms = array( $this->terms[2] );

		$html = $this->shortcode()->render();

		self::assertStringContainsString( '1 edición con tomos en catálogo', $html );
		self::assertStringNotContainsString( '1 ediciones', $html );
	}

	/**
	 * La barra del índice conserva la forma del alfabeto, pero las letras sin
	 * series NO son enlaces: un ancla a una sección inexistente deja al lector
	 * donde estaba y a un rastreador siguiendo un fragmento muerto.
	 *
	 * @testdox Should link only the letters that actually have a section.
	 */
	public function test_links_only_letters_with_a_section(): void {
		$html = $this->shortcode()->render();

		self::assertStringContainsString( 'href="#akb-series-a"', $html );
		self::assertStringContainsString( 'href="#akb-series-num"', $html );
		self::assertStringNotContainsString( 'href="#akb-series-q"', $html );
		self::assertStringContainsString( '--empty" aria-hidden="true">Q<', $html );
		// Y las 27 etiquetas siguen impresas: la barra no cambia de forma.
		self::assertSame( 27, substr_count( $html, 'akb-series-directory__letter-item' ) );
	}

	/**
	 * `#` no sobrevive a un `id`, así que el grupo de dígitos tiene ancla propia.
	 *
	 * @testdox Should give the hash group a usable anchor.
	 */
	public function test_hash_group_gets_a_usable_anchor(): void {
		self::assertStringContainsString( 'id="akb-series-num"', $this->shortcode()->render() );
	}

	/**
	 * @testdox Should ship the search box hidden so it never promises a feature JS did not load.
	 */
	public function test_search_box_ships_hidden(): void {
		self::assertStringContainsString( 'data-akb-series-filter hidden', $this->shortcode()->render() );
	}

	/**
	 * El input controla el conjunto de resultados y el contador es el estado vivo
	 * que anuncia cada filtro sin mover el foco del lector de pantalla.
	 *
	 * @testdox Should connect the search box to an atomic live result status.
	 */
	/**
	 * El TEXTO del contador vive en PHP, y el JS sólo sustituye el número.
	 *
	 * La copia del JS reconstruía la frase entera y la escribía en cada carga,
	 * así que la versión del servidor —la única que agrupa los miles con el
	 * locale del sitio— no llegaba a verse nunca, y la unidad editorial
	 * («edición»/«ediciones», que concuerda con el TOTAL y no con lo mostrado)
	 * vivía duplicada en dos lenguajes que podían separarse sin que ninguna
	 * prueba lo notara.
	 *
	 * @testdox Should publish both count templates so the client only fills in the number.
	 */
	public function test_publishes_the_count_templates_for_the_client(): void {
		$html = $this->shortcode()->render();

		self::assertStringContainsString( 'data-akb-series-count-all="3 ediciones con tomos en catálogo"', $html );
		self::assertStringContainsString( 'data-akb-series-count-filtered="%s de 3 ediciones con tomos en catálogo"', $html );
		self::assertStringContainsString( 'data-akb-series-locale=', $html );

		// El conteo crudo dejó de emitirse: nadie lo leía y el número ya viaja
		// horneado dentro de las dos plantillas.
		self::assertStringNotContainsString( 'data-akb-series-total=', $html );
		// Y el contenedor de resultados conserva su `id` —lo referencia el
		// `aria-controls` del buscador— pero no el data-attr que nadie leía.
		self::assertStringNotContainsString( 'data-akb-series-results', $html );
	}

	/**
	 * La concordancia la manda el TOTAL, no lo mostrado: con una sola serie las
	 * dos plantillas dicen «edición».
	 *
	 * @testdox Should agree in number with the total, not with the shown subset.
	 */
	public function test_count_templates_agree_with_the_total(): void {
		// Una sola serie publicable: las dos plantillas tienen que decir
		// «edición», no «ediciones».
		$this->terms = array_slice( $this->terms, 1, 1 );

		$html = $this->shortcode()->render();

		self::assertStringContainsString( 'data-akb-series-count-all="1 edición con tomos en catálogo"', $html );
		self::assertStringContainsString( 'data-akb-series-count-filtered="%s de 1 edición con tomos en catálogo"', $html );
	}

	public function test_search_box_has_an_accessible_result_contract(): void {
		$html = $this->shortcode()->render();

		self::assertStringContainsString( '>Buscar una serie o edición</label>', $html );
		self::assertStringContainsString( 'placeholder="Escribe el nombre de la serie o edición"', $html );
		self::assertStringContainsString( 'aria-label="Índice alfabético de series y ediciones"', $html );
		self::assertStringContainsString( 'aria-controls="akb-series-directory-results"', $html );
		self::assertStringContainsString( 'id="akb-series-directory-results"', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'aria-atomic="true"', $html );
		self::assertStringContainsString( 'aria-describedby="akb-series-directory-count"', $html );
	}

	/**
	 * El atributo de comportamiento permite limpiar el filtro antes de resolver
	 * el fragmento; de otro modo una letra puede apuntar a un grupo oculto.
	 *
	 * @testdox Should mark alphabet links for search-aware fragment navigation.
	 */
	public function test_marks_alphabet_links_for_search_aware_navigation(): void {
		$html = $this->shortcode()->render();

		self::assertStringContainsString( 'href="#akb-series-a" data-akb-series-letter', $html );
		self::assertStringNotContainsString( 'href="#akb-series-q" data-akb-series-letter', $html );
	}

	/**
	 * El texto de búsqueda viaja calculado desde el servidor: el JS compara dos
	 * cadenas plegadas por el MISMO criterio que ordena el índice.
	 *
	 * @testdox Should ship each entry with its server-folded search text.
	 */
	public function test_ships_the_folded_search_text(): void {
		self::assertStringContainsString( 'data-akb-serie="akira"', $this->shortcode()->render() );
	}

	/**
	 * @testdox Should cache the rendered directory for six hours.
	 */
	public function test_caches_the_rendered_directory(): void {
		$html = $this->shortcode()->render();

		self::assertSame( $html, $this->transients[ self::CACHE_KEY ] ?? null );
		self::assertSame( 6 * HOUR_IN_SECONDS, $this->transients[ self::CACHE_KEY . '::ttl' ] ?? null );
	}

	/**
	 * @testdox Should serve the cached HTML without querying the taxonomy again.
	 */
	public function test_serves_the_cache_without_touching_the_taxonomy(): void {
		$shortcode = $this->shortcode();
		$first     = $shortcode->render();

		self::assertSame( 1, $this->term_queries, 'Con el cache frío, UNA consulta para las N series.' );

		self::assertSame( $first, $shortcode->render() );
		self::assertSame( 1, $this->term_queries, 'Con el cache caliente no se vuelve a consultar la taxonomía.' );
	}

	/**
	 * Un contenedor vacío no aporta nada y cachear la ausencia la congelaría 6 h.
	 *
	 * @testdox Should print nothing and cache nothing when there are no publishable series.
	 */
	public function test_prints_nothing_without_publishable_series(): void {
		$this->terms = array();

		self::assertSame( '', $this->shortcode()->render() );
		self::assertSame( array(), $this->transients );
	}

	/** @testdox Should never cache or render a partial directory after an eligibility read failure. */
	public function test_fails_open_without_caching_a_partial_directory(): void {
		$first_term_taxonomy_id = (int) $this->terms[0]->term_taxonomy_id;
		$this->eligibility_database->rows = array(
			array(
				'term_taxonomy_id' => $first_term_taxonomy_id,
				'product_count'     => 11,
				'volume_count'      => 11,
			),
		);
		$eligibility = new SeriesCatalogEligibility( $this->eligibility_database );
		self::assertSame( 11, $eligibility->count( $first_term_taxonomy_id ), 'El primer término queda precargado.' );

		$this->eligibility_database->fail = true;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series catalogue eligibility unavailable' );

		self::assertSame( '', $this->shortcode( $eligibility )->render() );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/**
	 * @testdox Should drop the cache when a series term is created, edited or deleted.
	 */
	public function test_flushes_on_series_term_changes(): void {
		$shortcode = $this->shortcode();

		foreach ( array( 'created_pa_serie', 'edited_pa_serie', 'delete_pa_serie' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )->once()->with( array( $shortcode, 'flush_cache' ) );
		}

		$shortcode->register();

		self::assertTrue( true, 'Los tres hooks de término quedaron colgados.' );
	}

	/** @testdox Should register both final and direct-removal relationship boundaries. */
	public function test_registers_catalog_relationship_invalidation_hooks(): void {
		$shortcode = $this->shortcode();
		Monkey\Actions\expectAdded( 'set_object_terms' )
			->once()
			->with( array( $shortcode, 'flush_on_term_change' ), 10, 4 );
		Monkey\Actions\expectAdded( 'deleted_term_relationships' )
			->once()
			->with( array( $shortcode, 'flush_on_deleted_term_relationships' ), 10, 3 );

		$shortcode->register();

		self::assertTrue( true );
	}

	/** @testdox Should register all direct term-meta invalidation boundaries. */
	public function test_registers_public_term_metadata_invalidation_hooks(): void {
		$shortcode = $this->shortcode();
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )
				->once()
				->with( array( $shortcode, 'flush_on_public_term_meta' ), 10, 3 );
		}

		$shortcode->register();

		self::assertTrue( true );
	}

	/**
	 * La fila del directorio dice «N tomos en catálogo» y esa cifra cambia al
	 * marcar un tomo como otra versión de un número que la serie ya tiene, sin
	 * que se toque el término ni el estado del post. El comando del runbook es
	 * exactamente `wp post meta update <id> _akb_variante_tomo …`.
	 *
	 * @testdox Should register the volume-variant post-meta invalidation boundary.
	 */
	public function test_registers_variant_post_meta_invalidation_hooks(): void {
		$shortcode = $this->shortcode();
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )
				->once()
				->with( array( $shortcode, 'flush_on_variant_meta' ), 10, 3 );
		}

		$shortcode->register();

		self::assertTrue( true );
	}

	/** @testdox Should drop the cache when the volume-variant mark changes, and only then. */
	public function test_flushes_when_the_variant_mark_changes(): void {
		$shortcode = $this->shortcode();

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_variant_meta( 1, 27916, '_price' );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Otra postmeta de producto no invalida nada.' );

		$shortcode->flush_on_variant_meta( 1, 27916, VolumeVariant::META_KEY );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/** @testdox Should purge the cached hub page once at shutdown after repeated invalidations. */
	public function test_flushes_the_page_cache_once_per_request(): void {
		$shortcode = $this->shortcode();
		$transient_deletes = 0;
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) use ( &$transient_deletes ): bool {
				if ( self::CACHE_KEY === $key ) {
					++$transient_deletes;
				}
				unset( $this->transients[ $key ], $this->transients[ $key . '::ttl' ] );
				return true;
			}
		);
		Monkey\Actions\expectAdded( 'shutdown' )
			->once()
			->with( array( $shortcode, 'purge_hub_page_cache' ), PHP_INT_MAX );
		Monkey\Actions\expectDone( 'litespeed_purge_url' )
			->once()
			->with( 'https://akibara.cl/series/' );

		$shortcode->flush_cache();
		$shortcode->flush_cache();
		$shortcode->purge_hub_page_cache();

		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
		self::assertSame( 3, $transient_deletes, 'La barrera tardía vuelve a borrar un posible recalentamiento concurrente.' );
	}

	/**
	 * R2-8951cc1e: si el dueño mueve la página del directorio a otro slug, la
	 * purga tiene que apuntar a la URL real. Purgar `/series/` cuando esa URL ya
	 * no existe es una purga que no purga nada: el hub de verdad sigue sirviendo
	 * HTML viejo.
	 *
	 * @testdox Should purge the real page, not the literal, when the hub's slug moved.
	 */
	public function test_purges_the_real_page_when_the_hub_slug_moved(): void {
		$shortcode = $this->shortcode();
		Functions\when( 'get_posts' )->justReturn( array( 501 ) );
		Functions\when( 'get_post_field' )->justReturn( '[akibara_series_directory]' );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/todas-las-series/' );
		Monkey\Actions\expectDone( 'litespeed_purge_url' )
			->once()
			->with( 'https://akibara.cl/todas-las-series/' );

		$shortcode->flush_cache();
		$shortcode->purge_hub_page_cache();

		self::assertTrue( true );
	}

	/**
	 * WooCommerce recuenta los términos con un `UPDATE` directo y sin disparar
	 * ninguna acción de término, así que escuchar sólo `edited_pa_serie` dejaría
	 * los conteos congelados hasta el vencimiento.
	 *
	 * @testdox Should drop the cache when a product joins or leaves a series.
	 */
	public function test_flushes_when_a_product_changes_series(): void {
		$shortcode                           = $this->shortcode();
		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';

		$shortcode->flush_on_term_change( 1, array(), array(), 'pa_autor' );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Otra taxonomía no invalida nada.' );

		$shortcode->flush_on_term_change( 1, array(), array(), 'pa_serie' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_term_change( 1, array(), array(), 'product_visibility' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/** @testdox Should cover direct relationship removals that skip set_object_terms. */
	public function test_flushes_on_direct_relationship_removal(): void {
		$shortcode = $this->shortcode();
		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';

		$shortcode->flush_on_deleted_term_relationships( 1, array( 20 ), 'product_visibility' );

		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/**
	 * `save_post_product` NO se dispara al mandar un producto a la papelera ni
	 * al restaurarlo, y las dos cosas mueven el conteo de su serie.
	 *
	 * @testdox Should drop the cache when a product is trashed or restored.
	 */
	public function test_flushes_when_a_product_changes_status(): void {
		$shortcode = $this->shortcode();
		$product   = (object) array( 'post_type' => 'product' );
		$page      = (object) array( 'post_type' => 'page' );

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_status_change( 'publish', 'publish', $product );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Un guardado sin cambio de estado no mueve conteos.' );
		$shortcode->flush_on_status_change( 'pending', 'draft', $product );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Un cambio que no cruza publish no mueve el catálogo.' );

		$shortcode->flush_on_status_change( 'trash', 'publish', $page );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Una página no toca el catálogo.' );

		$shortcode->flush_on_status_change( 'trash', 'publish', $product );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/**
	 * Es el hook que sostiene «el directorio jamás enlaza una landing noindex»:
	 * sin él, poner una serie en noindex la dejaría enlazada hasta 6 h — justo la
	 * ventana en la que alguien va a revisar si funcionó.
	 *
	 * @testdox Should drop the cache when a public term-meta signal changes.
	 */
	public function test_flushes_when_public_term_metadata_changes(): void {
		$shortcode = $this->shortcode();

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_public_term_meta( 1, 10, 'akb_series_related_guide' );
		self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Otra meta de término no invalida nada.' );

		$shortcode->flush_on_public_term_meta( 1, 10, 'rank_math_robots' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_public_term_meta( 2, 10, SeriesSchemaType::META_KEY );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );

		// El estado de la edición es el chip de cada fila (UC-14).
		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_public_term_meta( 3, 10, PublicationStatus::META_EDITION_STATE );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}

	/**
	 * UC-14 (auditoría del tema 2026-09-22): el hub listaba 104 nombres sin decir
	 * cuáles se pueden tener completos. El estado de la edición, con la palabra
	 * de la landing, va junto al nombre; sin dato, nada.
	 *
	 * @testdox Should mark each edition with its publication state, and nothing when the owner left it empty.
	 */
	public function test_marks_the_edition_state_next_to_the_name(): void {
		$this->estados[1] = 'completa';
		$this->estados[2] = 'en_publicacion';
		$this->estados[3] = 'valor-raro';

		$html = $this->shortcode()->render();

		self::assertStringContainsString( '<span class="screen-reader-text">, </span><span class="akb-series-directory__estado akb-series-directory__estado--completa">Completa</span>', $html );
		self::assertStringContainsString( 'akb-series-directory__estado--en_publicacion">En publicación</span>', $html );
		self::assertSame( 2, substr_count( $html, 'class="akb-series-directory__estado ' ), 'Un valor fuera del conjunto no se publica.' );
	}

	/** @testdox Should not invalidate the directory for stock-only changes. */
	public function test_does_not_register_stock_invalidation_hooks(): void {
		$shortcode = $this->shortcode();
		Monkey\Actions\expectAdded( 'woocommerce_product_set_stock_status' )->never();
		Monkey\Actions\expectAdded( 'woocommerce_product_set_stock' )->never();

		$shortcode->register();

		self::assertTrue( true );
	}

	/**
	 * Un one-shot (completa en 1 tomo) se rotula como «Tomo único» en vez de
	 * «Completa», y alimenta su propia faceta de filtrado.
	 *
	 * @testdox Should label a one-volume complete series as Tomo único and expose the one-shot facet.
	 */
	public function test_labels_one_volume_complete_series_as_tomo_unico(): void {
		$this->estados[1] = 'completa';       // 11 tomos → Completa
		$this->estados[2] = 'en_publicacion'; // 6 tomos → En publicación
		$this->estados[3] = 'completa';       // 1 tomo → Tomo único

		$html = $this->shortcode()->render();

		self::assertStringContainsString(
			'<span class="akb-series-directory__estado akb-series-directory__estado--completa akb-series-directory__estado--tomo_unico">Tomo único</span>',
			$html
		);
		self::assertStringContainsString( 'data-akb-estado="tomo_unico"', $html );
		self::assertStringContainsString( 'data-akb-filter-estado="tomo_unico"', $html );
	}

	/**
	 * Cuando las series tienen editorial, país y ediciones relacionadas, el
	 * directorio imprime el sello de origen, la referencia cruzada y los chips
	 * de filtro por país y editorial.
	 *
	 * @testdox Should render origin badges, related-edition hints and country/brand facet chips.
	 */
	public function test_renders_origin_badges_related_hints_and_facets(): void {
		$this->terms = array();
		$this->term( 607, 'fumando-juntos-detras-del-super', 'Fumando juntos detrás del súper', 5 );
		$this->term( 653, 'fumando-atras-del-super-con-vos', 'Fumando atrás del súper con vos', 9 );

		Functions\when( 'get_term_meta' )->alias(
			function ( int $term_id, string $key, bool $single = false ) {
				unset( $single );
				if ( '_akb_serie_ediciones_relacionadas' === $key ) {
					return 607 === $term_id ? '653' : ( 653 === $term_id ? '607' : '' );
				}
				if ( PublicationStatus::META_EDITION_STATE === $key ) {
					return 'en_publicacion';
				}
				return '';
			}
		);

		$this->eligibility_database->rows = array(
			array(
				'term_taxonomy_id' => 607,
				'product_count'    => 5,
				'volume_count'     => 5,
				'brand_name'       => 'Panini Manga',
				'brand_slug'       => 'panini-manga',
				'country_name'     => 'España',
				'country_slug'     => 'espana',
			),
			array(
				'term_taxonomy_id' => 653,
				'product_count'    => 9,
				'volume_count'     => 9,
				'brand_name'       => 'Editorial Ivrea',
				'brand_slug'       => 'editorial-ivrea',
				'country_name'     => 'Argentina',
				'country_slug'     => 'argentina',
			),
		);

		$shortcode = new Shortcode( new SeriesIndex( new SeriesCatalogEligibility( $this->eligibility_database ) ) );
		$html      = $shortcode->render();

		self::assertStringContainsString( '<span class="akb-series-directory__origin">Editorial Ivrea · Argentina</span>', $html );
		self::assertStringContainsString( '<span class="akb-series-directory__origin">Panini Manga · España</span>', $html );
		self::assertStringContainsString( '<span class="akb-series-directory__related-hint">También como: Fumando juntos detrás del súper</span>', $html );
		self::assertStringContainsString( 'data-akb-filter-pais="argentina"', $html );
		self::assertStringContainsString( 'data-akb-filter-pais="espana"', $html );
		self::assertStringContainsString( 'data-akb-filter-editorial="editorial-ivrea"', $html );
		self::assertStringContainsString( 'data-akb-filter-editorial="panini-manga"', $html );
		self::assertStringContainsString( 'data-akb-pais="argentina"', $html );
		self::assertStringContainsString( 'data-akb-editorial="editorial-ivrea"', $html );
	}

	/**
	 * Cambiar la editorial, el país o las ediciones relacionadas invalida el caché del directorio.
	 *
	 * @testdox Should flush cache when brand, country or related-editions term meta changes.
	 */
	public function test_flushes_when_brand_country_or_related_editions_change(): void {
		$shortcode = $this->shortcode();

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_term_change( 1, array(), array(), 'product_brand' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_term_change( 1, array(), array(), 'pa_pais' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );

		$this->transients[ self::CACHE_KEY ] = '<div>viejo</div>';
		$shortcode->flush_on_public_term_meta( 4, 607, '_akb_serie_ediciones_relacionadas' );
		self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
	}
}
