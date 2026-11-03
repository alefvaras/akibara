<?php
/**
 * El texto propio de cada landing de autor, compuesto del catálogo.
 *
 * R6-autores-sin-texto (2026-09-19): 92 landings /autor/ sin una palabra
 * propia, 69 con cero impresiones y las de autores insignia fuera del índice.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoEnhancements;

use Akibara\Business\Modules\SeoEnhancements\AuthorIntro;
use Akibara\Business\Modules\SeoEnhancements\SeoEnhancements;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AuthorIntroTest extends TestCase {

	/** @var array<string, mixed> Transients vivos. */
	private array $transients = array();

	/** @var array<int, int> Ids que devuelve `get_objects_in_term`. */
	private array $asignados = array();

	/** @var array<int, int> Ids publicados que devuelve `get_posts`. */
	private array $publicados = array();

	/** @var array<int, object> Relaciones producto→término (`all_with_object_id`). */
	private array $relaciones = array();

	private int $consultas = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		$this->asignados  = array();
		$this->publicados = array();
		$this->relaciones = array();
		$this->consultas  = 0;

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_objects_in_term' )->alias(
			function (): array {
				++$this->consultas;
				return $this->asignados;
			}
		);
		Functions\when( 'get_posts' )->alias( fn (): array => $this->publicados );
		Functions\when( 'wp_get_object_terms' )->alias( fn (): array => $this->relaciones );
		Functions\when( 'get_term_link' )->alias( static fn ( object $t ) => 'https://akibara.cl/serie/' . $t->slug . '/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $name Nombre.
	 * @param int    $count Productos.
	 * @return \WP_Term
	 */
	private function autor( string $name = 'Kentaro Miura', int $count = 3 ): \WP_Term {
		$term           = new \WP_Term( 77, 'kentaro-miura', $name );
		$term->taxonomy = 'pa_autor';
		$term->count    = $count;

		return $term;
	}

	/**
	 * @param int    $object_id Producto.
	 * @param string $taxonomy  Taxonomía.
	 * @param int    $term_id   Término.
	 * @param string $name      Nombre.
	 * @return object
	 */
	private static function relacion( int $object_id, string $taxonomy, int $term_id, string $name ): object {
		return (object) array(
			'object_id' => $object_id,
			'taxonomy'  => $taxonomy,
			'term_id'   => $term_id,
			'name'      => $name,
			'slug'      => strtolower( str_replace( ' ', '-', $name ) ),
		);
	}

	/** @testdox Sin tomos no hay frase: mejor callar que inventar. */
	public function test_sin_tomos_no_hay_frase(): void {
		self::assertSame( '', AuthorIntro::compose( 'Kentaro Miura', 0, array(), array() ) );
		self::assertSame( '', AuthorIntro::compose( '  ', 5, array(), array() ) );
	}

	/** @testdox Un tomo de una serie de una editorial: singulares y la serie enlazada a su guía. */
	public function test_un_tomo_una_serie(): void {
		$html = AuthorIntro::compose(
			'Tite Kubo',
			1,
			array(
				array(
					'name' => 'Bleach',
					'url'  => 'https://akibara.cl/serie/bleach/',
				),
			),
			array( 'Panini' )
		);

		self::assertSame(
			'<p>Tite Kubo en Akibara: 1 tomo de la serie <a href="https://akibara.cl/serie/bleach/">Bleach</a>, editado por Panini. Cada serie tiene su propia guía con sus tomos, disponibles o a pedido.</p>',
			$html
		);
	}

	/** @testdox Varias series: se nombran tres, se cuentan las demás, y las editoriales van enumeradas. */
	public function test_varias_series_y_editoriales(): void {
		$series = array();
		foreach ( array( 'Berserk', 'Gigantomaquia', 'Berserk Deluxe', 'Ōrō', 'Japan' ) as $i => $name ) {
			$series[] = array(
				'name' => $name,
				'url'  => 0 === $i ? 'https://akibara.cl/serie/berserk/' : '',
			);
		}

		$html = AuthorIntro::compose( 'Kentaro Miura', 41, $series, array( 'Panini', 'Ivrea' ) );

		self::assertStringContainsString( 'Kentaro Miura en Akibara: 41 tomos de 5 series —<a href="https://akibara.cl/serie/berserk/">Berserk</a>, Gigantomaquia, Berserk Deluxe y 2 más—, editados por Panini y Ivrea.', $html );
		self::assertStringStartsWith( '<p>', $html );
	}

	/** @testdox Dos series sin editorial: «A y B» y la frase cierra sin coma colgando. */
	public function test_dos_series_sin_editorial(): void {
		$html = AuthorIntro::compose(
			'Naoki Urasawa',
			12,
			array(
				array(
					'name' => 'Monster',
					'url'  => '',
				),
				array(
					'name' => '20th Century Boys',
					'url'  => '',
				),
			),
			array()
		);

		self::assertStringContainsString( '12 tomos de 2 series —Monster y 20th Century Boys—. Cada serie', $html );
	}

	/** @testdox El nombre del autor y de las series se escapan. */
	public function test_escapa_lo_que_viene_del_catalogo(): void {
		$html = AuthorIntro::compose( 'A <b>B</b>', 2, array( array( 'name' => 'C & D', 'url' => '' ) ), array( 'E <i>F</i>' ) );

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( 'C &amp; D', $html );
		self::assertStringNotContainsString( '<i>', $html );
	}

	/**
	 * @testdox for_term() cuenta sólo lo publicado, ordena las series de más a menos tomos y cachea 12 h por término y número de productos.
	 */
	public function test_for_term_lee_el_catalogo_y_cachea(): void {
		$this->asignados  = array( 1, 2, 3, 4 );
		$this->publicados = array( 1, 2, 3 ); // El 4 está en borrador.
		$this->relaciones = array(
			self::relacion( 1, 'pa_serie', 10, 'Gigantomaquia' ),
			self::relacion( 2, 'pa_serie', 11, 'Berserk' ),
			self::relacion( 3, 'pa_serie', 11, 'Berserk' ),
			self::relacion( 1, 'product_brand', 20, 'Panini' ),
			self::relacion( 2, 'product_brand', 20, 'Panini' ),
		);

		$intro = new AuthorIntro();
		$term  = $this->autor();
		$html  = $intro->for_term( $term );

		self::assertStringContainsString( 'Kentaro Miura en Akibara: 3 tomos de 2 series —<a href="https://akibara.cl/serie/berserk/">Berserk</a> y <a href="https://akibara.cl/serie/gigantomaquia/">Gigantomaquia</a>—, editados por Panini.', $html );
		self::assertArrayHasKey( 'akb_autor_intro_77_3', $this->transients, 'La clave lleva término y número de productos.' );

		$intro->for_term( $term );
		self::assertSame( 1, $this->consultas, 'La segunda vez sale del transient.' );

		// Entra un tomo: cambia el count, cambia la clave, se vuelve a consultar.
		$intro->for_term( $this->autor( 'Kentaro Miura', 4 ) );
		self::assertSame( 2, $this->consultas );
	}

	/** @testdox [NEG] Un término de otra taxonomía, o sin productos publicados, no produce texto. */
	public function test_otra_taxonomia_o_sin_publicados_no_produce_texto(): void {
		$intro = new AuthorIntro();

		$genero           = $this->autor( 'Seinen' );
		$genero->taxonomy = 'pa_genero';
		self::assertSame( '', $intro->for_term( $genero ) );
		self::assertSame( '', $intro->for_term( null ) );

		$this->asignados  = array( 9 );
		$this->publicados = array();
		self::assertSame( '', $intro->for_term( $this->autor() ) );
		self::assertSame( '', $intro->meta_description( $this->autor() ) );
	}

	/**
	 * Revisión independiente H4: la meta se compone de los hechos, no de
	 * re-cortar el HTML. Un «Dr. STONE» tiene un «. » dentro y un «&» no
	 * puede salir como entidad (Rank Math escapa al imprimir).
	 *
	 * @testdox La meta description es la primera frase en texto plano: sin entidades y sin partirse en un «Dr.».
	 */
	public function test_meta_description_es_la_primera_frase(): void {
		$this->asignados  = array( 1, 2 );
		$this->publicados = array( 1, 2 );
		$this->relaciones = array(
			self::relacion( 1, 'pa_serie', 11, 'Dr. STONE' ),
			self::relacion( 2, 'pa_serie', 12, "JoJo's Bizarre Adventure" ),
			self::relacion( 1, 'product_brand', 20, 'Ivrea & Co' ),
		);

		self::assertSame(
			"Riichiro Inagaki en Akibara: 2 tomos de 2 series —Dr. STONE y JoJo's Bizarre Adventure—, editados por Ivrea & Co.",
			( new AuthorIntro() )->meta_description( $this->autor( 'Riichiro Inagaki', 2 ) )
		);
	}

	/**
	 * R9-plantilla-autor-inalcanzable (2026-09-20): la guarda que cedía ante una
	 * plantilla global de Rank Math leía `rank_math_titles`, option inexistente,
	 * y nunca corrió. Se retiró la promesa; la precedencia real es: meta o
	 * descripción del término mandan, la plantilla global no.
	 *
	 * @testdox La meta de Rank Math del término manda sobre el párrafo; la plantilla global de autor, no.
	 */
	public function test_la_precedencia_de_la_meta_de_autor(): void {
		$this->asignados  = array( 1 );
		$this->publicados = array( 1 );
		$this->relaciones = array( self::relacion( 1, 'pa_serie', 11, 'Berserk' ) );
		$term             = $this->autor( 'Kentaro Miura', 1 );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias( static fn( $tax = '' ): bool => 'pa_autor' === $tax );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => 'rank-math-options-titles' === $name
				? array( 'tax_pa_autor_description' => 'Todos los títulos de %term% disponibles en Akibara Chile.' )
				: $default
		);
		$sut = new SeoEnhancements( true );

		Functions\when( 'get_term_meta' )->justReturn( '' );
		self::assertSame(
			'Kentaro Miura en Akibara: 1 tomo de la serie Berserk.',
			$sut->prefer_author_term_description( 'Todos los títulos de Kentaro Miura disponibles en Akibara Chile.' ),
			'La plantilla global (un valor por defecto para toda la taxonomía) no desplaza el párrafo específico del autor.'
		);

		Functions\when( 'get_term_meta' )->alias( static fn( $id, $key = '' ) => 'rank_math_description' === $key ? 'Escrita a mano para Miura.' : '' );
		self::assertSame(
			'Escrita a mano para Miura.',
			$sut->prefer_author_term_description( 'Escrita a mano para Miura.' ),
			'Lo escrito PARA ESE AUTOR manda.'
		);
	}

	/** @testdox Revisión H7: con descripción propia del término no hay texto automático, pregunte quien pregunte. */
	public function test_con_descripcion_propia_no_hay_texto_automatico(): void {
		$this->asignados   = array( 1 );
		$this->publicados  = array( 1 );
		$this->relaciones  = array( self::relacion( 1, 'pa_serie', 11, 'Berserk' ) );
		$term              = $this->autor( 'Kentaro Miura', 1 );
		$term->description = 'Texto del dueño.';
		$intro             = new AuthorIntro();

		self::assertSame( '', $intro->for_term( $term ) );
		self::assertSame( '', $intro->meta_description( $term ) );
		self::assertSame( 0, $this->consultas, 'Ni siquiera consulta el catálogo.' );
	}

	/** @testdox Revisión H10: las editoriales también se acotan a tres y «N más». */
	public function test_las_editoriales_se_acotan_como_las_series(): void {
		$html = AuthorIntro::compose(
			'Osamu Tezuka',
			30,
			array( array( 'name' => 'Astro Boy', 'url' => '' ) ),
			array( 'Planeta', 'Ivrea', 'Panini', 'Milky Way', 'Norma' )
		);

		self::assertStringContainsString( 'editados por Planeta, Ivrea, Panini y 2 más.', $html );
	}

	/** @testdox En la pantalla del término el dueño ve el texto automático y cómo reemplazarlo; con descripción propia, se le dice que manda la suya. */
	public function test_la_pantalla_del_termino_ensena_el_texto(): void {
		$this->asignados  = array( 1 );
		$this->publicados = array( 1 );
		$this->relaciones = array( self::relacion( 1, 'pa_serie', 11, 'Berserk' ) );
		$intro            = new AuthorIntro();

		ob_start();
		$intro->render_admin_preview( $this->autor( 'Kentaro Miura', 1 ) );
		$sin_descripcion = (string) ob_get_clean();
		self::assertStringContainsString( 'Kentaro Miura en Akibara: 1 tomo', $sin_descripcion );
		self::assertStringContainsString( 'escribe una descripción arriba', $sin_descripcion );

		$term              = $this->autor( 'Kentaro Miura', 1 );
		$term->description = 'Texto del dueño.';
		ob_start();
		$intro->render_admin_preview( $term );
		$con_descripcion = (string) ob_get_clean();
		self::assertStringContainsString( 'muestra la descripción de arriba', $con_descripcion );
		self::assertStringNotContainsString( 'en Akibara:', $con_descripcion );
	}

	/** @testdox register() engancha la vista previa en la pantalla de edición del término, y el módulo queda cableado. */
	public function test_register_y_cableado(): void {
		$intro = new AuthorIntro();
		Monkey\Actions\expectAdded( 'pa_autor_edit_form_fields' )->once()->with( array( $intro, 'render_admin_preview' ), 10, 1 );
		$intro->register();
		$this->addToAssertionCount( 1 );

		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
		self::assertSame( 1, substr_count( $plugin, "['author_intro'] = new AuthorIntro()" ) );
		self::assertSame( 1, substr_count( $plugin, "['author_intro']->register()" ) );

		$seo = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeoEnhancements/SeoEnhancements.php' );
		self::assertStringContainsString( '( new AuthorIntro() )->for_term( $term )', $seo, 'El archivo de autor sin descripción pinta el texto automático.' );
	}
}
