<?php
/**
 * Pruebas del render puro del manifiesto llms.txt.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LlmsManifest;

use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Term;

/**
 * Doble de `wpdb` que responde a las DOS consultas del manifiesto: el agregado
 * de catálogo por término y el lote de `rank_math_robots` por producto.
 */
final class LlmsManifestMetaWpdb {
	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $last_error         = '';
	public bool $ready                = true;

	/** @var list<array<string,mixed>> */
	public array $rows_por_termino = array();

	/** @var array<int,string> Valor crudo de `rank_math_robots` por producto. */
	public array $robots = array();

	public bool $robots_falla = false;

	public int $consultas_de_robots = 0;

	/**
	 * @param string $query   Consulta con marcadores.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/**
	 * @param string $query  Consulta preparada.
	 * @param string $output Formato pedido.
	 * @return array<int,mixed>
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $output );

		// El agregado de elegibilidad TAMBIÉN toca `wp_postmeta` desde 1.116.0
		// (el `LEFT JOIN` de la marca de variante), así que el discriminador no
		// puede ser la tabla: es la consulta de robots la que se distingue.
		if ( false !== strpos( $query, 'series_relationship' ) || false === strpos( $query, 'wp_postmeta' ) ) {
			return $this->rows_por_termino;
		}

		++$this->consultas_de_robots;
		if ( $this->robots_falla ) {
			$this->last_error = 'simulated robots query failure';
			return array();
		}

		$filas = array();
		foreach ( $this->robots as $post_id => $valor ) {
			$filas[] = (object) array( 'post_id' => $post_id, 'meta_value' => $valor );
		}

		return $filas;
	}
}

final class LlmsManifestTest extends TestCase {

	/** Inserción con semántica INSERT IGNORE sobre el almacén de la prueba. @var \Closure|null */
	private ?\Closure $insercion = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<int,array{name:string,url:string,note:string,volumes:array<int,array{title:string,url:string}>}> */
	private static function series(): array {
		return array(
			array(
				'name'    => 'Jujutsu Kaisen',
				'url'     => 'https://akibara.cl/serie/jujutsu-kaisen/',
				'note'    => 'Jujutsu Kaisen de Gege Akutami: serie completa en 30 tomos.',
				'volumes' => array(
					array(
						'title' => 'Jujutsu Kaisen 01',
						'url'   => 'https://akibara.cl/jujutsu-kaisen-1-panini-argentina/',
					),
				),
			),
		);
	}

	/** @return array<int,array{title:string,url:string,note:string}> */
	private static function guides(): array {
		return array(
			array(
				'title' => 'Panini vs Ivrea',
				'url'   => 'https://akibara.cl/panini-vs-ivrea/',
				'note'  => 'Comparativa de ediciones.',
			),
		);
	}

	/**
	 * @testdox Should follow the llmstxt.org spec: H1 first, then the blockquote summary.
	 */
	/**
	 * GEO 2026-09-01: las landings de editorial entran al manifiesto con su
	 * conteo; sin editoriales la sección no aparece (compatibilidad con los
	 * llamadores de tres argumentos).
	 */
	public function test_lists_brands_between_series_and_guides(): void {
		$brands = array(
			array( 'name' => 'Ivrea Argentina', 'url' => 'https://akibara.cl/marca/ivrea-argentina/', 'note' => '855 títulos catalogados en Akibara Chile.' ),
			array( 'name' => '', 'url' => 'https://akibara.cl/marca/x/', 'note' => 'sin nombre: se salta' ),
		);
		$text = LlmsManifest::render( self::series(), self::guides(), false, array(), $brands );

		self::assertStringContainsString( "## Editoriales (1)\n\n- [Ivrea Argentina](https://akibara.cl/marca/ivrea-argentina/): 855 títulos catalogados en Akibara Chile.", $text );
		self::assertLessThan( strpos( $text, '## Editoriales' ), strpos( $text, '## Series' ), 'Las series van antes.' );
		self::assertLessThan( strpos( $text, '## Guías' ), strpos( $text, '## Editoriales' ), 'Las guías van después.' );
		self::assertStringNotContainsString( '## Editoriales', LlmsManifest::render( self::series(), self::guides(), false ), 'Sin editoriales no hay sección.' );
	}

	public function test_starts_with_h1_then_blockquote(): void {
		$text  = LlmsManifest::render( self::series(), self::guides(), false );
		$lines = explode( "\n", $text );

		self::assertSame( '# Akibara', $lines[0], 'La primera línea debe ser el H1, no un comentario.' );
		self::assertStringStartsWith( '> ', $lines[2], 'El resumen va en blockquote, no dentro del H1.' );
	}

	/**
	 * @testdox Should list every series as an annotated link.
	 */
	public function test_lists_series_with_annotations(): void {
		$text = LlmsManifest::render( self::series(), self::guides(), false );

		self::assertStringContainsString( '## Series (1)', $text );
		self::assertStringContainsString(
			'- [Jujutsu Kaisen](https://akibara.cl/serie/jujutsu-kaisen/): Jujutsu Kaisen de Gege Akutami: serie completa en 30 tomos.',
			$text
		);
	}

	/** @testdox Should expose the canonical blog hub as well as individual guides. */
	public function test_lists_the_blog_hub(): void {
		$text = LlmsManifest::render( self::series(), self::guides(), false );

		self::assertStringContainsString( '- [Blog y guías](https://akibara.cl/blog/)', $text );
	}

	/**
	 * @testdox Should tell agents when to reach for Akibara, not just what it sells.
	 *
	 * Un manifiesto que sólo lista URLs le dice a un agente QUÉ hay, nunca
	 * CUÁNDO usarlo. La guía de llms.txt pide una sección de encaje, y una
	 * auditoría del 2026-08-28 marcó su ausencia como fallo.
	 */
	public function test_declares_when_to_use(): void {
		$text = LlmsManifest::render( self::series(), self::guides(), false );

		self::assertStringContainsString( '## Cuándo usar Akibara', $text );

		// Lo que hace útil la sección es el límite, no el elogio: sin el «no
		// sirve para», vuelve a ser copy de marketing.
		self::assertStringContainsString( 'No sirve para:', $text );
		self::assertStringContainsString( 'solo dentro de Chile', $text );

		// Y la vía de consulta, que es la novedad del release.
		self::assertStringContainsString( 'Accept: text/markdown', $text );
	}

	/**
	 * @testdox Should not duplicate the help links that already have a section.
	 *
	 * El primer intento añadió un bloque «Páginas de referencia» que repetía
	 * «## Ayuda y políticas» entero. Un manifiesto con los mismos enlaces dos
	 * veces le cuesta contexto al agente y no le aporta nada.
	 */
	public function test_does_not_duplicate_the_help_section(): void {
		$text = LlmsManifest::render( self::series(), self::guides(), false );

		self::assertSame( 1, substr_count( $text, 'https://akibara.cl/nosotros/' ) );
		self::assertSame( 1, substr_count( $text, 'https://akibara.cl/contacto/' ) );
		self::assertStringNotContainsString( '### Páginas de referencia', $text );
	}

	/** @testdox Should use the public packaging benefit name in the support links. */
	public function test_uses_the_public_packaging_benefit_name(): void {
		$text = LlmsManifest::render( self::series(), self::guides(), false );

		self::assertStringContainsString( '- [Embalaje reforzado](https://akibara.cl/embalaje-seguro/)', $text );
		self::assertStringNotContainsString( '- [Embalaje seguro](', $text );
	}

	/**
	 * @testdox Should include volumes only in the full manifest.
	 */
	public function test_volumes_only_in_full_manifest(): void {
		$compact = LlmsManifest::render( self::series(), self::guides(), false );
		$full    = LlmsManifest::render( self::series(), self::guides(), true );

		self::assertStringNotContainsString( 'Jujutsu Kaisen 01', $compact );
		self::assertStringContainsString( '  - [Jujutsu Kaisen 01](https://akibara.cl/jujutsu-kaisen-1-panini-argentina/)', $full );
		self::assertStringContainsString( '/llms-full.txt', $compact, 'El compacto enlaza al extendido.' );
		self::assertStringNotContainsString( '/llms-full.txt', $full, 'El extendido no se enlaza a sí mismo.' );
	}

	/**
	 * El archivo puede vivir 24 h: un precio impreso sería el mismo defecto que
	 * las 859 metas con precio horneado purgadas el 2026-08-15.
	 *
	 * @testdox Should never contain prices or stock claims.
	 */
	public function test_contains_no_volatile_data(): void {
		$full = LlmsManifest::render( self::series(), self::guides(), true );

		self::assertStringContainsString( 'con tomos disponibles, a pedido e importación. Despacho a todo Chile', $full );
		self::assertStringContainsString( '[Disponibles](https://akibara.cl/disponibles/): productos disponibles para comprar ahora, sin espera de importación', $full );
		self::assertStringNotContainsString( '$', $full );
		self::assertStringNotContainsString( 'CLP', $full );
		self::assertStringNotContainsString( 'en stock', $full );
		self::assertStringNotContainsString( 'stock propio', mb_strtolower( $full, 'UTF-8' ) );
		self::assertStringNotContainsString( 'stock inmediato', mb_strtolower( $full, 'UTF-8' ) );
		self::assertStringNotContainsString( 'stock físico', mb_strtolower( $full, 'UTF-8' ) );
		self::assertStringNotContainsString( 'bodega', mb_strtolower( $full, 'UTF-8' ) );
		self::assertStringContainsString( 'todo chile', mb_strtolower( $full, 'UTF-8' ) );
		self::assertStringNotContainsString( 'envío nacional', mb_strtolower( $full, 'UTF-8' ) );
	}

	/**
	 * @testdox Should expose indexable products that have no indexable series landing.
	 */
	public function test_lists_products_outside_indexable_series(): void {
		$products = array(
			array(
				'title' => 'El arte de Berserk',
				'url'   => 'https://akibara.cl/el-arte-de-berserk-panini-espana/',
			),
		);
		$text     = LlmsManifest::render( self::series(), self::guides(), false, $products );

		self::assertStringContainsString( '## Otros productos', $text );
		self::assertStringContainsString(
			'- [El arte de Berserk](https://akibara.cl/el-arte-de-berserk-panini-espana/)',
			$text
		);
	}

	/**
	 * @testdox Should collapse editorial content to one line and escape Markdown delimiters.
	 */
	public function test_normalizes_and_escapes_adversarial_markdown(): void {
		$series = array(
			array(
				'name'    => "Serie ](https://evil.example)\n## Instrucción",
				'url'     => 'https://akibara.cl/serie/segura/',
				'note'    => "Nota\n- [pwn](https://evil.example) <script>alert(1)</script>",
				'volumes' => array(
					array(
						'title' => "Tomo [1]\r\n## Otro",
						'url'   => 'https://akibara.cl/tomo-1/',
					),
				),
			),
		);

		$text = LlmsManifest::render( $series, array(), true );

		self::assertStringContainsString(
			'- [Serie \\]\\(https://evil.example\\) ## Instrucción](https://akibara.cl/serie/segura/)',
			$text
		);
		self::assertStringContainsString( 'Nota - \\[pwn\\]\\(https://evil.example\\) alert\\(1\\)', $text );
		self::assertStringContainsString( '  - [Tomo \\[1\\] ## Otro](https://akibara.cl/tomo-1/)', $text );
		self::assertStringNotContainsString( "\n## Instrucción", $text );
		self::assertStringNotContainsString( '<script>', $text );
		self::assertStringNotContainsString( '](https://evil.example)', $text );
	}

	/**
	 * @testdox Should atomically replace a manifest and preserve the old file when rename fails.
	 */
	public function test_replaces_manifests_atomically_and_preserves_the_old_file_on_failure(): void {
		$directory = sys_get_temp_dir() . '/akb-llms-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $directory, 0700 ) );
		$target = $directory . '/llms.txt';

		try {
			self::assertSame( 4, file_put_contents( $target, "old\n" ) );
			self::assertFalse(
				LlmsManifest::replace_file_atomically(
					$target,
					"new but rejected\n",
					static fn( string $source, string $destination ): bool => false
				)
			);
			self::assertSame( "old\n", file_get_contents( $target ) );
			self::assertSame( array(), glob( $directory . '/.akibara-llms-*' ) );

			self::assertFalse(
				LlmsManifest::replace_file_atomically(
					$target,
					"new but thrown\n",
					static function ( string $source, string $destination ): bool {
						unset( $source, $destination );
						throw new \RuntimeException( 'simulated rename failure' );
					}
				)
			);
			self::assertSame( "old\n", file_get_contents( $target ) );
			self::assertSame( array(), glob( $directory . '/.akibara-llms-*' ) );

			self::assertTrue( LlmsManifest::replace_file_atomically( $target, "new\n" ) );
			self::assertSame( "new\n", file_get_contents( $target ) );
			self::assertSame( array(), glob( $directory . '/.akibara-llms-*' ) );
		} finally {
			if ( is_file( $target ) ) {
				unlink( $target );
			}
			rmdir( $directory );
		}
	}

	/**
	 * @testdox Should publish a valid empty series catalogue and expose non-series products.
	 */
	public function test_writes_valid_empty_series_catalogue(): void {
		$directory = $this->temporary_directory();
		$options   = array(
			LlmsManifest::OPTION_LAST_ERROR => array(
				'timestamp' => 1_699_999_000,
				'code'      => 'old_error',
				'message'   => '',
			),
		);
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				return 'product' === ( $args['post_type'] ?? '' )
					? array( (object) array( 'ID' => 41, 'post_title' => 'Producto sin serie' ) )
					: array();
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/producto-sin-serie/' );

		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_000,
			token_factory: static fn(): string => str_repeat( 'a', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			$compact = (string) file_get_contents( $directory . '/llms.txt' );
			$full    = (string) file_get_contents( $directory . '/llms-full.txt' );
			self::assertStringContainsString( '## Series (0)', $compact );
			self::assertStringContainsString( '- [Producto sin serie](https://akibara.cl/producto-sin-serie/)', $compact );
			self::assertStringContainsString( '## Series (0)', $full );
			self::assertSame( 0, $options[ LlmsManifest::OPTION_LAST_SUCCESS ]['series'] );
			self::assertSame( 1, $options[ LlmsManifest::OPTION_LAST_SUCCESS ]['products'] );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_LAST_ERROR, $options, 'Un éxito persistido resuelve el error anterior.' );
			self::assertFalse( $autoload[ LlmsManifest::OPTION_LAST_SUCCESS ] );
			self::assertFalse( $autoload[ LlmsManifest::OPTION_MANAGED_FILES ] );
			self::assertFalse( $autoload[ LlmsManifest::OPTION_LOCK ] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * 2026-09-19 (R3-d89de454): un producto suelto o una guía con `post_password`
	 * puesto (protegidos por contraseña) no deben aparecer en `llms.txt` ni en
	 * `llms-full.txt` — ni siquiera con su extracto. `llms.txt` es un archivo
	 * físico servido a CUALQUIERA sin pasar por ninguna plantilla, así que es la
	 * misma fuga que `MarkdownNegotiation` cerraba para la ficha en vivo.
	 *
	 * @testdox Should exclude password-protected products and guides from both manifests.
	 */
	public function test_excludes_password_protected_content(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				if ( 'product' === ( $args['post_type'] ?? '' ) ) {
					return array(
						(object) array( 'ID' => 41, 'post_title' => 'Producto público', 'post_password' => '' ),
						(object) array( 'ID' => 42, 'post_title' => 'Producto con contraseña', 'post_password' => 'clave-secreta' ),
					);
				}
				if ( 'post' === ( $args['post_type'] ?? '' ) ) {
					return array(
						(object) array(
							'ID'            => 51,
							'post_title'    => 'Guía pública',
							'post_excerpt'  => 'Resumen público de la guía.',
							'post_password' => '',
						),
						(object) array(
							'ID'            => 52,
							'post_title'    => 'Guía con contraseña',
							'post_excerpt'  => 'Extracto que no debería filtrarse.',
							'post_password' => 'clave-secreta',
						),
					);
				}
				return array();
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ): string {
				$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
				return "https://akibara.cl/item-{$id}/";
			}
		);

		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_050,
			token_factory: static fn(): string => str_repeat( 'p', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			$compact = (string) file_get_contents( $directory . '/llms.txt' );
			$full    = (string) file_get_contents( $directory . '/llms-full.txt' );

			foreach ( array( $compact, $full ) as $contenido ) {
				self::assertStringContainsString( 'Producto público', $contenido );
				self::assertStringContainsString( 'Guía pública', $contenido );
				self::assertStringNotContainsString( 'contraseña', $contenido );
				self::assertStringNotContainsString( 'clave-secreta', $contenido );
				self::assertStringNotContainsString( 'no debería filtrarse', $contenido );
				self::assertStringNotContainsString( 'https://akibara.cl/item-42/', $contenido );
				self::assertStringNotContainsString( 'https://akibara.cl/item-52/', $contenido );
			}
			self::assertSame( 1, $options[ LlmsManifest::OPTION_LAST_SUCCESS ]['products'], 'El producto con contraseña no cuenta como publicado.' );
			self::assertSame( 1, $options[ LlmsManifest::OPTION_LAST_SUCCESS ]['guides'], 'La guía con contraseña no cuenta como publicada.' );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should preserve both existing manifests and record an unreadable series query.
	 */
	public function test_preserves_existing_manifests_when_series_query_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );

		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'database_unavailable', 'offline' ) );
		Functions\expect( 'get_posts' )->never();

		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_100,
			token_factory: static fn(): string => str_repeat( 'b', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'series_terms_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
			self::assertFalse( $autoload[ LlmsManifest::OPTION_LAST_ERROR ] );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_LAST_SUCCESS, $options );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should not treat an empty term array accompanied by wpdb error as a valid empty catalogue.
	 */
	public function test_preserves_existing_manifests_when_term_query_silences_database_error(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		Functions\when( 'get_terms' )->alias(
			static function (): array {
				$GLOBALS['wpdb']->last_error = 'simulated term query failure';
				return array();
			}
		);
		Functions\expect( 'get_posts' )->never();
		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_150,
			token_factory: static fn(): string => str_repeat( 'k', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'series_terms_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should propagate a volume-query database error and preserve both manifests.
	 */
	public function test_preserves_existing_manifests_when_volume_query_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		$database    = new class() {
			public string $posts = 'wp_posts';
			public string $terms = 'wp_terms';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $postmeta           = 'wp_postmeta';
			public string $last_error = '';
			public string $last_query = '';
			public bool $ready = true;

			public function prepare( string $query, ...$args ): string {
				unset( $query, $args );
				return 'prepared-series-counts';
			}

			/** @return array<int,array{term_taxonomy_id:int,product_count:int}> */
			public function get_results( string $query, string $output ): array {
				unset( $output );
				$this->last_query = $query;
				return array(
					array(
						'term_taxonomy_id' => 7,
						'product_count'     => 1,
					),
				);
			}
		};
		$eligibility = new SeriesCatalogEligibility( $database );
		$term        = new WP_Term( 7, 'serie-segura', 'Serie segura' );
		$term->taxonomy = 'pa_serie';

		Functions\when( 'get_terms' )->justReturn( array( $term ) );
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single ) {
				unset( $term_id, $single );
				return 'rank_math_description' === $key ? 'Descripción manual.' : '';
			}
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/serie-segura/' );
		Functions\when( 'get_posts' )->alias(
			static function (): array {
				$GLOBALS['wpdb']->last_error = 'simulated volume query failure';
				return array();
			}
		);

		$sut = new LlmsManifest(
			series_catalog_eligibility: $eligibility,
			document_root: $directory,
			clock: static fn(): int => 1_700_000_175,
			token_factory: static fn(): string => str_repeat( 'l', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'series_volume_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should preserve both manifests when the guide query reports a database error.
	 */
	public function test_preserves_existing_manifests_when_guide_query_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				if ( 'post' === ( $args['post_type'] ?? '' ) ) {
					$GLOBALS['wpdb']->last_error = 'simulated guide query failure';
				}
				return array();
			}
		);

		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_200,
			token_factory: static fn(): string => str_repeat( 'g', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'guide_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should preserve both manifests when the standalone-product query reports a database error.
	 */
	public function test_preserves_existing_manifests_when_product_query_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				if ( 'product' === ( $args['post_type'] ?? '' ) ) {
					$GLOBALS['wpdb']->last_error = 'simulated product query failure';
				}
				return array();
			}
		);

		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_300,
			token_factory: static fn(): string => str_repeat( 'h', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'product_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should restore the exact full preimage when compact publication fails.
	 */
	public function test_rolls_back_full_when_compact_write_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		$replacer = static function ( string $target, string $contents ): bool {
			return 'llms.txt' === basename( $target )
				? false
				: LlmsManifest::replace_file_atomically( $target, $contents );
		};
		$sut      = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_400,
			token_factory: static fn(): string => str_repeat( 'i', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion,
			file_replacer: $replacer
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'llms_compact_write_failed_rolled_back', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_LAST_SUCCESS, $options );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should expose a distinct error when compact failure cannot roll back the full file.
	 */
	public function test_observes_full_rollback_failure(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );

		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		$full_writes = 0;
		$replacer   = static function ( string $target, string $contents ) use ( &$full_writes ): bool {
			if ( 'llms.txt' === basename( $target ) ) {
				return false;
			}
			++$full_writes;
			return 1 === $full_writes && LlmsManifest::replace_file_atomically( $target, $contents );
		};
		$sut        = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_700_000_500,
			token_factory: static fn(): string => str_repeat( 'j', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion,
			file_replacer: $replacer
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertNotSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame( 'llms_compact_write_failed_rollback_failed', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should skip a concurrent generation while its lease remains live.
	 */
	public function test_live_lease_prevents_a_second_generation(): void {
		$directory = $this->temporary_directory();
		$options   = array(
			LlmsManifest::OPTION_LOCK => array(
				'token'      => str_repeat( 'c', 32 ),
				'expires_at' => 2_000,
			),
		);
		$autoload = array();
		$cas      = $this->stub_option_store( $options, $autoload );

		Functions\expect( 'get_terms' )->never();
		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_000,
			token_factory: static fn(): string => str_repeat( 'd', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( str_repeat( 'c', 32 ), $options[ LlmsManifest::OPTION_LOCK ]['token'] );
			self::assertFileDoesNotExist( $directory . '/llms.txt' );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_LAST_ERROR, $options );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should reclaim an expired lease with compare-and-swap.
	 */
	public function test_expired_lease_is_reclaimed(): void {
		$directory = $this->temporary_directory();
		$options   = array(
			LlmsManifest::OPTION_LOCK => array(
				'token'      => str_repeat( 'e', 32 ),
				'expires_at' => 999,
			),
		);
		$autoload = array();
		$cas      = $this->stub_option_store( $options, $autoload );

		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'database_unavailable', 'offline' ) );
		$sut = new LlmsManifest(
			document_root: $directory,
			clock: static fn(): int => 1_000,
			token_factory: static fn(): string => str_repeat( 'f', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( '', $options[ LlmsManifest::OPTION_LOCK ], 'El nuevo owner libera su lease exacto.' );
			self::assertSame( 'series_terms_query_unreadable', $options[ LlmsManifest::OPTION_LAST_ERROR ]['code'] );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should delete only hash-owned regular manifests during lifecycle cleanup.
	 */
	public function test_lifecycle_cleanup_preserves_tampered_files(): void {
		$directory = $this->temporary_directory();
		$options   = array(
			LlmsManifest::OPTION_MANAGED_FILES => array(
				'llms.txt'      => array( hash( 'sha256', "owned\n" ) ),
				'llms-full.txt' => array( hash( 'sha256', "generated\n" ) ),
			),
			LlmsManifest::OPTION_LOCK => array(
				'token'      => str_repeat( 'a', 32 ),
				'expires_at' => 2_000,
			),
		);
		$autoload = array();
		$this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "owned\n" );
		file_put_contents( $directory . '/llms-full.txt', "edited by a human\n" );

		try {
			LlmsManifest::remove_manifests( $directory );

			self::assertFileDoesNotExist( $directory . '/llms.txt' );
			self::assertFileExists( $directory . '/llms-full.txt' );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_MANAGED_FILES, $options );
			self::assertArrayNotHasKey( LlmsManifest::OPTION_LOCK, $options );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * @testdox Should wire manifest cron and owned-file cleanup into deactivation and uninstall.
	 */
	public function test_lifecycle_sources_clear_schedule_and_remove_manifests(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$deactivate  = (string) file_get_contents( $plugin_root . '/akibara-business.php' );
		$uninstall   = (string) file_get_contents( $plugin_root . '/uninstall.php' );

		// El cron ya no se nombra en cada superficie: las dos retiran la lista
		// única de `OwnCrons` (S-0e169ea5, 2026-09-19), que tiene que traerlo.
		self::assertContains( LlmsManifest::HOOK, \Akibara\Business\Utilities\OwnCrons::hooks() );

		foreach ( array( $deactivate, $uninstall ) as $source ) {
			self::assertStringContainsString( '\Akibara\Business\Utilities\OwnCrons::unschedule_all();', $source );
			self::assertStringContainsString( 'remove_manifests();', $source );
		}
	}

	/**
	 * INVARIANTE: los tomos de TODAS las series se resuelven en UNA consulta.
	 *
	 * El bucle viejo consultaba los tomos serie por serie. Medido en Docker el
	 * 2026-08-23 (99 series, `SAVEQUERIES`, transients calientes en ambos lados):
	 * la corrida completa del cron pasó de 1.282 a 428 consultas, con `llms.txt`
	 * y `llms-full.txt` byte a byte idénticos (sha256 comparado). El candado fija
	 * la propiedad —una sola consulta de tomos, sea cual sea el número de
	 * series—, no el número medido.
	 *
	 * @testdox Should resolve the volumes of every series with a single catalog query.
	 */
	public function test_resolves_every_series_volume_in_one_query(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		$database = new class() {
			public string $posts = 'wp_posts';
			public string $terms = 'wp_terms';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $postmeta           = 'wp_postmeta';
			public string $last_error = '';
			public bool $ready = true;

			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}

			/** @return array<int,array{term_taxonomy_id:int,product_count:int}> */
			public function get_results( string $query, string $output ): array {
				unset( $query, $output );
				return array(
					array( 'term_taxonomy_id' => 7, 'product_count' => 1 ),
					array( 'term_taxonomy_id' => 8, 'product_count' => 1 ),
				);
			}
		};

		$primera            = new WP_Term( 7, 'serie-a', 'Serie A' );
		$primera->taxonomy  = 'pa_serie';
		$segunda            = new WP_Term( 8, 'serie-b', 'Serie B' );
		$segunda->taxonomy  = 'pa_serie';

		$product_queries = 0;
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array( $primera, $segunda ) );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/'
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->alias(
			static fn( $post ): string => 'https://akibara.cl/tomo-' . $post->ID . '/'
		);
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$product_queries ): array {
				if ( 'product' !== ( $args['post_type'] ?? '' ) ) {
					return array(); // Guías: `post_type` 'post', fuera de la cuenta.
				}
				++$product_queries;
				return array(
					(object) array( 'ID' => 11, 'post_title' => 'Serie A 01' ),
					(object) array( 'ID' => 22, 'post_title' => 'Serie B 01' ),
				);
			}
		);
		Functions\when( 'wp_get_object_terms' )->justReturn(
			array(
				(object) array( 'object_id' => 11, 'term_id' => 7 ),
				(object) array( 'object_id' => 22, 'term_id' => 8 ),
			)
		);

		$sut = new LlmsManifest(
			series_catalog_eligibility: new SeriesCatalogEligibility( $database ),
			document_root: $directory,
			clock: static fn(): int => 1_700_000_200,
			token_factory: static fn(): string => str_repeat( 'v', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			$full = (string) file_get_contents( $directory . '/llms-full.txt' );
			self::assertStringContainsString( '  - [Serie A 01](https://akibara.cl/tomo-11/)', $full );
			self::assertStringContainsString( '  - [Serie B 01](https://akibara.cl/tomo-22/)', $full );
			self::assertSame(
				2,
				$product_queries,
				'Una consulta de tomos para TODAS las series más la de productos sueltos: nunca una por serie.'
			);
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * INVARIANTE fail-closed: si la lectura de relaciones serie↔producto no es
	 * confiable, se conserva el manifiesto de ayer.
	 *
	 * La consulta agrupada añadió una segunda lectura (`wp_get_object_terms`).
	 * Sin este guard, una base degradada publicaría un `llms-full.txt` con todas
	 * las series vacías y pasaría por bueno.
	 *
	 * @testdox Should preserve both manifests when the series relation read is unreliable.
	 */
	public function test_preserves_existing_manifests_when_series_relations_fail(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );
		$GLOBALS['wpdb'] = (object) array(
			'last_error' => '',
			'ready'      => true,
		);

		$database = new class() {
			public string $posts = 'wp_posts';
			public string $terms = 'wp_terms';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $postmeta           = 'wp_postmeta';
			public string $last_error = '';
			public bool $ready = true;

			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}

			/** @return array<int,array{term_taxonomy_id:int,product_count:int}> */
			public function get_results( string $query, string $output ): array {
				unset( $query, $output );
				return array( array( 'term_taxonomy_id' => 7, 'product_count' => 1 ) );
			}
		};

		$term           = new WP_Term( 7, 'serie-a', 'Serie A' );
		$term->taxonomy = 'pa_serie';

		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array( $term ) );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/serie-a/' );
		Functions\when( 'get_posts' )->justReturn(
			array( (object) array( 'ID' => 11, 'post_title' => 'Serie A 01' ) )
		);
		Functions\when( 'wp_get_object_terms' )->alias(
			static function (): array {
				$GLOBALS['wpdb']->last_error = 'simulated relation query failure';
				return array();
			}
		);

		$sut = new LlmsManifest(
			series_catalog_eligibility: new SeriesCatalogEligibility( $database ),
			document_root: $directory,
			clock: static fn(): int => 1_700_000_210,
			token_factory: static fn(): string => str_repeat( 'w', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame(
				'series_volume_relations_unreadable',
				$options[ LlmsManifest::OPTION_LAST_ERROR ]['code']
			);
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * El manifiesto lee UNA clave por producto (`rank_math_robots`).
	 *
	 * `get_posts()` ceba por defecto el postmeta COMPLETO de los ~1.566
	 * productos —decenas de filas por producto en una tienda WooCommerce— para
	 * descartarlo todo salvo esa clave: +85 MB y +545 ms en la corrida diaria
	 * del cron. Las dos consultas de producto lo apagan y la clave se resuelve
	 * en una consulta por lote.
	 *
	 * @testdox Should never prime the whole product postmeta to read one key.
	 */
	public function test_product_queries_do_not_prime_the_whole_postmeta(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		$consultas = array();
		$metadatos = array();

		$database = new LlmsManifestMetaWpdb();
		$database->rows_por_termino = array( array( 'term_taxonomy_id' => 7, 'product_count' => 2 ) );
		$database->robots           = array( 22 => 'a:1:{i:0;s:7:"noindex";}' );
		$GLOBALS['wpdb']            = $database;

		$term           = new WP_Term( 7, 'serie-a', 'Serie A' );
		$term->taxonomy = 'pa_serie';

		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array( $term ) );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/serie-a/' );
		Functions\when( 'get_permalink' )->alias(
			static fn( $post ): string => 'https://akibara.cl/tomo-' . $post->ID . '/'
		);
		Functions\when( 'maybe_unserialize' )->alias(
			static fn( $value ) => is_string( $value ) && 0 === strpos( $value, 'a:' ) ? unserialize( $value ) : $value
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$metadatos ) {
				$metadatos[] = array( $id, $key );
				return '';
			}
		);
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$consultas ): array {
				if ( 'product' !== ( $args['post_type'] ?? '' ) ) {
					return array();
				}
				$consultas[] = $args;
				return array(
					(object) array( 'ID' => 11, 'post_title' => 'Serie A 01' ),
					(object) array( 'ID' => 22, 'post_title' => 'Serie A 02' ),
				);
			}
		);
		Functions\when( 'wp_get_object_terms' )->justReturn(
			array(
				(object) array( 'object_id' => 11, 'term_id' => 7 ),
				(object) array( 'object_id' => 22, 'term_id' => 7 ),
			)
		);

		$sut = new LlmsManifest(
			series_catalog_eligibility: new SeriesCatalogEligibility( $database ),
			document_root: $directory,
			clock: static fn(): int => 1_700_000_300,
			token_factory: static fn(): string => str_repeat( 'p', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertNotSame( array(), $consultas );
			foreach ( $consultas as $args ) {
				self::assertFalse(
					$args['update_post_meta_cache'] ?? true,
					'Cebar el postmeta completo del catálogo para leer una clave cuesta +85 MB en el cron.'
				);
			}

			// La clave se resolvió por lote, no producto a producto.
			self::assertSame( array(), $metadatos, 'Ningún get_post_meta suelto por producto.' );
			self::assertGreaterThan( 0, $database->consultas_de_robots );

			// Y el veredicto es el mismo que daba la lectura vieja: el tomo con
			// `noindex` no entra al manifiesto.
			$full = (string) file_get_contents( $directory . '/llms-full.txt' );
			self::assertStringContainsString( 'Serie A 01', $full );
			self::assertStringNotContainsString( 'Serie A 02', $full );
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/**
	 * Fail-closed: si la clave de robots no se puede leer, se conserva el
	 * manifiesto de ayer. Publicar como indexable una URL que pide `noindex`
	 * sería el error silencioso que esta disciplina existe para evitar.
	 *
	 * @testdox Should preserve both manifests when the robots read is unreliable.
	 */
	public function test_preserves_existing_manifests_when_the_robots_read_fails(): void {
		$directory = $this->temporary_directory();
		$options   = array();
		$autoload  = array();
		$cas       = $this->stub_option_store( $options, $autoload );
		file_put_contents( $directory . '/llms.txt', "compact old\n" );
		file_put_contents( $directory . '/llms-full.txt', "full old\n" );

		$database = new LlmsManifestMetaWpdb();
		$database->rows_por_termino = array( array( 'term_taxonomy_id' => 7, 'product_count' => 1 ) );
		$database->robots_falla     = true;
		$GLOBALS['wpdb']            = $database;

		$term           = new WP_Term( 7, 'serie-a', 'Serie A' );
		$term->taxonomy = 'pa_serie';

		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( array( $term ) );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/serie-a/' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/tomo-11/' );
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				return 'product' === ( $args['post_type'] ?? '' )
					? array( (object) array( 'ID' => 11, 'post_title' => 'Serie A 01' ) )
					: array();
			}
		);
		Functions\when( 'wp_get_object_terms' )->justReturn(
			array( (object) array( 'object_id' => 11, 'term_id' => 7 ) )
		);

		$sut = new LlmsManifest(
			series_catalog_eligibility: new SeriesCatalogEligibility( $database ),
			document_root: $directory,
			clock: static fn(): int => 1_700_000_400,
			token_factory: static fn(): string => str_repeat( 'q', 32 ),
			option_compare_and_swap: $cas,
			option_insert_if_absent: $this->insercion
		);

		try {
			$sut->write_manifests();

			self::assertSame( "compact old\n", file_get_contents( $directory . '/llms.txt' ) );
			self::assertSame( "full old\n", file_get_contents( $directory . '/llms-full.txt' ) );
			self::assertSame(
				'series_volume_robots_unreadable',
				$options[ LlmsManifest::OPTION_LAST_ERROR ]['code']
			);
		} finally {
			$this->remove_directory( $directory );
		}
	}

	/** Crea un directorio aislado para manifiestos físicos. */
	private function temporary_directory(): string {
		$directory = sys_get_temp_dir() . '/akb-llms-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $directory, 0700 ) );
		return $directory;
	}

	/** Elimina sólo los tres archivos exactos usados por estas pruebas. */
	private function remove_directory( string $directory ): void {
		foreach ( array( 'llms.txt', 'llms-full.txt' ) as $filename ) {
			$target = $directory . '/' . $filename;
			if ( is_file( $target ) || is_link( $target ) ) {
				unlink( $target );
			}
		}
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}

	/**
	 * Simula wp_options y devuelve el CAS exacto que usa el lease en producción.
	 *
	 * @param array<string,mixed> $options Opciones mutables.
	 * @param array<string,bool>  $autoload Flags recibidos.
	 * @return \Closure(string,mixed,mixed):bool
	 */
	private function stub_option_store( array &$options, array &$autoload ): \Closure {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( string $name, $value = '', string $deprecated = '', $autoload_value = null ) use ( &$options, &$autoload ): bool {
				unset( $deprecated );
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}
				$options[ $name ]  = $value;
				$autoload[ $name ] = (bool) $autoload_value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value, $autoload_value = null ) use ( &$options, &$autoload ): bool {
				$changed           = ! array_key_exists( $name, $options ) || $options[ $name ] !== $value;
				$options[ $name ]  = $value;
				$autoload[ $name ] = (bool) $autoload_value;
				return $changed;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$options, &$autoload ): bool {
				$existed = array_key_exists( $name, $options );
				unset( $options[ $name ], $autoload[ $name ] );
				return $existed;
			}
		);

		// La vía rápida del lease es INSERT IGNORE: no pisa una fila existente, a
		// diferencia de `add_option()` (revisión independiente, 2026-09-15).
		$this->insercion = static function ( string $name, $value ) use ( &$options, &$autoload ): bool {
			if ( array_key_exists( $name, $options ) ) {
				return false;
			}
			$options[ $name ]  = $value;
			$autoload[ $name ] = false;
			return true;
		};

		return static function ( string $name, $expected, $replacement ) use ( &$options, &$autoload ): bool {
			if ( ! array_key_exists( $name, $options ) || $options[ $name ] !== $expected ) {
				return false;
			}
			$options[ $name ]  = $replacement;
			$autoload[ $name ] = false;
			return true;
		};
	}

	/**
	 * `add_option()` hace INSERT … ON DUPLICATE KEY UPDATE: con la caché de objetos
	 * desfasada pisa el lease vivo de otra corrida y devuelve verdadero
	 * (revisión independiente, 2026-09-15).
	 *
	 * @testdox El lease nunca se toma con add_option(): la vía rápida es la inserción atómica.
	 */
	public function test_the_lease_is_never_taken_with_add_option(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/LlmsManifest/LlmsManifest.php' );
		$fuente = (string) preg_replace( '#/\*[\s\S]*?\*/|//[^\n]*#', '', $fuente );

		self::assertDoesNotMatchRegularExpression( '/add_option\(\s*self::OPTION_LOCK/', $fuente );
		self::assertStringContainsString( '( $this->option_insert_if_absent )( self::OPTION_LOCK, $lease )', $fuente );
		self::assertStringContainsString( 'OptionRow::insert_if_absent( $option, $value )', $fuente, 'Por defecto, INSERT IGNORE.' );
	}
	/**
	 * @testdox Los directorios de series y de editoriales salen con la URL que se le pasa; sin ella, el slug de fábrica.
	 */
	public function test_the_hub_links_follow_the_resolved_urls(): void {
		$de_fabrica = LlmsManifest::render( array(), array(), false );
		self::assertStringContainsString( '- [Series](https://akibara.cl/series/)', $de_fabrica );
		self::assertStringContainsString( '- [Editoriales](https://akibara.cl/editoriales/)', $de_fabrica );

		$movidos = LlmsManifest::render(
			array(),
			array(),
			false,
			array(),
			array(),
			array(
				'series' => 'https://akibara.cl/todas-las-series/',
				'brands' => 'https://akibara.cl/por-editorial/',
			)
		);
		self::assertStringContainsString( '- [Series](https://akibara.cl/todas-las-series/)', $movidos );
		self::assertStringContainsString( '- [Editoriales](https://akibara.cl/por-editorial/)', $movidos );
		self::assertStringNotContainsString( '(https://akibara.cl/series/)', $movidos );

		// Una URL que no lo es no entra al manifiesto: vuelve el slug de fábrica.
		$basura = LlmsManifest::render( array(), array(), false, array(), array(), array( 'series' => 'javascript:alert(1)' ) );
		self::assertStringContainsString( '- [Series](https://akibara.cl/series/)', $basura );
	}
}
