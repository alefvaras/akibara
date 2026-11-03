<?php
/**
 * Pruebas del auto-linker de series del blog.
 *
 * El conjunto enlazable NO se simula: se arma con el `SeriesIndex` real sobre
 * un doble de base que respeta las mismas reglas que producción (conteo de
 * catálogo publicable y `rank_math_robots`). Un doble del propio índice haría
 * pasar estas pruebas aunque el blog dejara de consultar la autoridad — que es
 * exactamente el defecto que vienen a cerrar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Blog;

use Akibara\Business\Modules\Blog\Blog;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

/** Doble de `wpdb` para el agregado de catálogo del índice de series. */
final class BlogEligibilityWpdb {
	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';

	/** @var list<array<string,mixed>> */
	public array $rows = array();

	public string $last_error = '';

	public bool $ready = true;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $query, $output );
		return $this->rows;
	}
}

final class BlogTest extends TestCase {

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var list<WP_Term> */
	private array $terms = array();

	/** @var array<int,mixed> */
	private array $robots = array();

	private BlogEligibilityWpdb $database;

	/** @var mixed */
	private $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients    = array();
		$this->terms         = array();
		$this->robots        = array();
		$this->database      = new BlogEligibilityWpdb();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = (object) array(
			'num_queries' => 0,
			'last_error'  => '',
			'ready'       => true,
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_terms' )->alias( fn(): array => $this->terms );
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = false ) => $this->robots[ $term_id ] ?? ''
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => is_object( $term ) ? 'https://akibara.cl/catalogo/serie/' . $term->slug . '/' : ''
		);
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'is_single' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * INVARIANTE: el blog enlaza EXACTAMENTE lo que el directorio publica.
	 *
	 * Antes se consultaba `get_terms( hide_empty => true )` a secas, y eso
	 * enlazaba las dos series que `SeriesIndex` documenta que no hay que
	 * enlazar (las `noindex`): mandar al lector a una página que le pedimos a
	 * Google que no indexe, y gastar rastreo en ella.
	 *
	 * @testdox Should never link a noindex series from a blog post.
	 */
	public function test_never_links_a_noindex_series(): void {
		$this->term( 7, 'vinland-saga', 'Vinland Saga', 12 );
		$this->term( 8, 'pack-vinland-saga-123', 'Pack Vinland Saga 123', 1 );
		$this->robots[8] = array( 'noindex' );

		$contenido = $this->blog()->auto_product_links(
			'<p>Hablemos de Vinland Saga y del Pack Vinland Saga 123.</p>'
		);

		self::assertStringContainsString(
			'<a href="https://akibara.cl/catalogo/serie/vinland-saga/" class="blog-product-link"',
			$contenido
		);
		self::assertSame(
			1,
			substr_count( $contenido, 'blog-product-link' ),
			'La serie noindex se menciona, pero no se enlaza.'
		);
	}

	/**
	 * Una serie sin catálogo público tampoco se enlaza: el enlace llevaría a
	 * una landing vacía.
	 *
	 * @testdox Should not link a series without public catalogue.
	 */
	public function test_does_not_link_a_series_without_public_catalogue(): void {
		$this->term( 7, 'akira', 'Akira', 0 );

		self::assertStringNotContainsString(
			'blog-product-link',
			$this->blog()->auto_product_links( '<p>Akira es un clásico.</p>' )
		);
	}

	/**
	 * La URL sale de `get_term_link()` a través de `SeriesIndex`, no de un
	 * `/serie/{slug}/` armado a mano: `pa_serie` es jerárquica y su base la
	 * fijan los permalinks del sitio.
	 *
	 * @testdox Should use the resolved term permalink instead of a hand-built path.
	 */
	public function test_uses_the_resolved_term_permalink(): void {
		$this->term( 7, 'akira', 'Akira', 6 );

		$contenido = $this->blog()->auto_product_links( '<p>Akira es un clásico.</p>' );

		self::assertStringContainsString( 'href="https://akibara.cl/catalogo/serie/akira/"', $contenido );
		self::assertStringNotContainsString( 'href="https://akibara.cl/serie/akira/"', $contenido );
	}

	/**
	 * Una lectura degradada devuelve el conjunto vacío igual que un catálogo
	 * realmente vacío. Cachearla 24 h dejaría el blog sin un solo enlace
	 * interno por un fallo de un segundo.
	 *
	 * @testdox Should not cache an empty map produced by an unreliable read.
	 */
	public function test_does_not_cache_an_unreliable_empty_map(): void {
		$this->term( 7, 'akira', 'Akira', 6 );
		$GLOBALS['wpdb']->ready = false;

		$this->blog()->auto_product_links( '<p>Akira.</p>' );

		self::assertSame( array(), $this->transients );
	}

	/**
	 * Un catálogo vacío LEÍDO BIEN sí se cachea: es la verdad, no un fallo.
	 *
	 * @testdox Should cache a reliable empty map.
	 */
	public function test_caches_a_reliable_empty_map(): void {
		$this->blog()->auto_product_links( '<p>Akira.</p>' );

		self::assertSame( array( 'akb_blog_series_map' => array() ), $this->transients );
	}

	/**
	 * El mapa se arma con el CATÁLOGO de cada serie, no sólo con el término:
	 * publicar el primer tomo de una serie —o sacarle el último— cambia el
	 * conjunto sin tocar la taxonomía.
	 *
	 * @testdox Should purge the map when a product enters or leaves a series.
	 */
	public function test_purges_the_map_when_product_series_change(): void {
		$blog = $this->blog();

		$this->transients['akb_blog_series_map'] = array( 'Akira' => 'https://akibara.cl/catalogo/serie/akira/' );
		$blog->flush_series_map_for_object( 11, array( 5 ), array( 5 ), 'pa_serie', false, array() );
		self::assertSame( array(), $this->transients, 'Entrar en una serie cambia el conjunto.' );

		// Reordenar los MISMOS términos no cambia nada: no se purga.
		$this->transients['akb_blog_series_map'] = array( 'Akira' => 'https://akibara.cl/catalogo/serie/akira/' );
		$blog->flush_series_map_for_object( 11, array( 5, 6 ), array( 6, 5 ), 'pa_serie', false, array( 5, 6 ) );
		self::assertArrayHasKey( 'akb_blog_series_map', $this->transients );

		// Otra taxonomía tampoco: editar las categorías de una entrada del blog
		// no puede tirar el mapa de series.
		$blog->flush_series_map_for_object( 11, array( 9 ), array( 9 ), 'category', false, array() );
		self::assertArrayHasKey( 'akb_blog_series_map', $this->transients );
	}

	/**
	 * El match es INSENSIBLE a la caja desde el 2026-09-01: exigir la caja
	 * exacta del término dejaba 12 de los 31 posts del blog sin un solo enlace
	 * interno, porque quien redacta escribe «one piece» y el término dice
	 * «One Piece».
	 *
	 * El texto del autor se CONSERVA: se enlaza lo que él escribió, no el
	 * nombre del término.
	 *
	 * @testdox Should link a series written in a different case, keeping the author's text.
	 */
	public function test_links_a_series_written_in_another_case_and_keeps_the_text(): void {
		$this->term( 7, 'one-piece', 'One Piece', 107 );

		$contenido = $this->blog()->auto_product_links( '<p>Estoy al día con one piece.</p>' );

		self::assertStringContainsString( 'href="https://akibara.cl/catalogo/serie/one-piece/"', $contenido );
		self::assertStringContainsString( '>one piece</a>', $contenido, 'Se enlaza el texto ORIGINAL, no el nombre del término.' );
		self::assertStringNotContainsString( '>One Piece</a>', $contenido );
	}

	/**
	 * Insensible a la caja no significa enlazar de más: sigue siendo UNA vez
	 * por serie y por post, y sigue exigiendo palabra completa.
	 *
	 * @testdox Should still link each series once and only on whole words.
	 */
	public function test_case_insensitive_still_links_once_and_on_whole_words(): void {
		$this->term( 7, 'akira', 'Akira', 6 );

		$contenido = $this->blog()->auto_product_links( '<p>AKIRA, akira y Akiras.</p>' );

		self::assertSame( 1, substr_count( $contenido, 'blog-product-link' ), 'Una sola vez por serie y por post.' );
		self::assertStringContainsString( '>AKIRA</a>', $contenido, 'Enlaza la PRIMERA mención, tal como se escribió.' );
		self::assertStringNotContainsString( '>Akiras</a>', $contenido, '`\b` sigue exigiendo palabra completa.' );
	}

	/**
	 * La excepción que sostiene el cambio: en un blog de manga «la saga de
	 * Naruto» NO puede convertirse en un enlace al cómic **Saga**. Esa serie
	 * —y sólo las de `CASE_SENSITIVE_NAMES`— sigue exigiendo la caja del término.
	 *
	 * @testdox Should not link a common Spanish word that collides with a series name.
	 */
	public function test_does_not_link_a_generic_spanish_word_colliding_with_a_series(): void {
		$this->term( 7, 'saga', 'Saga', 12 );

		$contenido = $this->blog()->auto_product_links( '<p>La saga de este autor es larga.</p>' );

		self::assertStringNotContainsString(
			'blog-product-link',
			$contenido,
			'«saga» en minúsculas es la palabra española, no el cómic.'
		);

		// Y escrita como el término SÍ enlaza: la excepción no la deja muda.
		self::assertStringContainsString(
			'blog-product-link',
			$this->blog()->auto_product_links( '<p>Estoy leyendo Saga, de Vaughan.</p>' )
		);
	}

	/** @testdox Should link overlapping series without corrupting URLs or visible text. */
	public function test_overlapping_series_never_reprocess_generated_links(): void {
		$this->term( 7, 'naruto-jump-remix', 'Naruto Jump Remix', 24 );
		$this->term( 8, 'naruto', 'Naruto', 72 );
		$this->term( 9, 'akira', 'Akira', 6 );

		$content = '<p><em>Naruto Jump Remix</em> reúne tres tomos. Naruto y Akira son otras ediciones. Naruto Jump Remix vuelve a mencionarse.</p>';
		$result  = $this->blog()->auto_product_links( $content );

		self::assertSame( 3, substr_count( $result, 'class="blog-product-link"' ), 'Each series receives one intact link.' );
		self::assertStringContainsString( 'href="https://akibara.cl/catalogo/serie/naruto-jump-remix/" class="blog-product-link" title="Ver Naruto Jump Remix en Akibara">Naruto Jump Remix</a>', $result );
		self::assertStringContainsString( 'href="https://akibara.cl/catalogo/serie/naruto/" class="blog-product-link" title="Ver Naruto en Akibara">Naruto</a>', $result );
		self::assertSame( strip_tags( $content ), strip_tags( $result ), 'Linking must not alter the article text.' );
	}

	/** @testdox Should preserve existing links and attribute values for overlapping series. */
	public function test_overlapping_series_preserve_authored_links_and_attributes(): void {
		$this->term( 7, 'naruto-jump-remix', 'Naruto Jump Remix', 24 );
		$this->term( 8, 'naruto', 'Naruto', 72 );

		$prefix = '<h2>Naruto Jump Remix</h2><a href="https://example.com/naruto" title="Naruto Jump Remix">Naruto</a><img alt="Naruto" src="naruto.jpg">';
		$result = $this->blog()->auto_product_links( $prefix . '<p>NARUTO JUMP REMIX</p>' );

		self::assertStringStartsWith( $prefix, $result );
		self::assertSame( 1, substr_count( $result, 'class="blog-product-link"' ) );
		self::assertStringContainsString( '>NARUTO JUMP REMIX</a>', $result );
	}

	/** @testdox Should hang both taxonomy and catalogue invalidation hooks. */
	public function test_registers_catalogue_invalidation_hooks(): void {
		$blog = $this->blog();
		$blog->register();

		self::assertNotFalse( Monkey\Actions\has( 'edited_pa_serie', array( $blog, 'flush_series_map' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'set_object_terms', array( $blog, 'flush_series_map_for_object' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'woocommerce_update_product', array( $blog, 'flush_series_map' ) ) );
	}

	/** Añade un término `pa_serie` con su conteo de catálogo publicable. */
	private function term( int $id, string $slug, string $name, int $catalog_count ): void {
		$term                   = new WP_Term( $id, $slug, $name );
		$term->taxonomy         = 'pa_serie';
		$term->term_taxonomy_id = $id;
		$term->count            = $catalog_count;
		$this->terms[]          = $term;

		if ( $catalog_count > 0 ) {
			$this->database->rows[] = array(
				'term_taxonomy_id' => $id,
				'product_count'    => $catalog_count,
			);
		}
	}

	private function blog(): Blog {
		return new Blog( new SeriesIndex( new SeriesCatalogEligibility( $this->database ) ) );
	}
}
