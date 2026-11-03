<?php
/**
 * Invariantes de coste de la pestaña «Descuentos» del panel.
 *
 * Contexto (medido en local el 2026-08-23, con `wp cache flush` antes de cada
 * lado): la pantalla costaba 310 consultas y 41,3 ms. 283 de esas consultas
 * eran `SELECT option_value FROM wp_options WHERE option_name = ?`, una por
 * fila: `WC_Admin_Settings::output_fields()` llama a `get_option()` por cada
 * campo que no trae `value`, y el `id` de estas filas no es una opción sino la
 * clave de un term-meta, así que ninguna existe y el caché `notoptions` sólo
 * ahorra la segunda lectura del MISMO nombre. Las otras se iban en pedir los
 * mismos términos 3–4 veces por taxonomía. Después: 24 consultas y 19,1 ms,
 * con el HTML byte a byte idéntico (219.586 bytes, 282 `<input>`).
 *
 * Estas pruebas fijan las dos INVARIANTES, no los números: (1) ningún campo
 * sintético delega su lectura en `get_option`, y (2) los términos de una
 * taxonomía se piden una sola vez por petición.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AdminScreenCostTest extends TestCase {

	/** Taxonomías «registradas» para la prueba. @var array<int, string> */
	private const TAXONOMIES = array( 'product_brand', 'product_cat' );

	/** Cada llamada a `get_terms`, en orden. @var array<int, array<string, mixed>> */
	private array $get_terms_calls = array();

	/** ¿Alguien contó términos por fuera de la lista renderizada? */
	private int $wp_count_terms_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// La tarjeta de estado lee el rastro del apagado automático (CampaignExpiryCron).
		Functions\when( 'get_transient' )->justReturn( false );
		$this->get_terms_calls      = array();
		$this->wp_count_terms_calls = 0;
		$this->stub_wordpress();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Ningún campo sintético deja que WooCommerce lo busque en wp_options.
	 */
	public function test_synthetic_fields_declare_their_own_value(): void {
		$fields = ( new TaxonomyDiscount() )->get_settings();

		$synthetic = array_filter(
			$fields,
			static fn( array $field ): bool => str_starts_with( (string) ( $field['type'] ?? '' ), 'akb_' )
		);

		self::assertNotEmpty( $synthetic, 'La pantalla dejó de emitir filas sintéticas.' );

		foreach ( $synthetic as $field ) {
			self::assertArrayHasKey(
				'value',
				$field,
				sprintf(
					'El campo «%s» (%s) no declara `value`: WC_Admin_Settings::output_fields() '
					. 'le hará un get_option() propio y volverá el N+1 de 283 consultas.',
					(string) $field['id'],
					(string) $field['type']
				)
			);
		}
	}

	/**
	 * @testdox Los términos de cada taxonomía se piden una sola vez por petición.
	 */
	public function test_terms_are_fetched_once_per_taxonomy(): void {
		$module = new TaxonomyDiscount();

		$module->get_settings();
		ob_start();
		$module->render_subtab_nav();
		ob_end_clean();

		$per_taxonomy = array();
		foreach ( $this->get_terms_calls as $args ) {
			$slug                  = (string) ( $args['taxonomy'] ?? '' );
			$per_taxonomy[ $slug ] = ( $per_taxonomy[ $slug ] ?? 0 ) + 1;
		}

		foreach ( $per_taxonomy as $slug => $calls ) {
			self::assertSame(
				1,
				$calls,
				sprintf( 'La taxonomía %s se pidió %d veces; la lista tiene que ser compartida.', $slug, $calls )
			);
		}

		self::assertSame( self::TAXONOMIES, array_keys( $per_taxonomy ) );
	}

	/**
	 * @testdox El resumen del acordeón cuenta sobre la lista que sí se renderiza.
	 */
	public function test_summary_counts_come_from_the_rendered_list(): void {
		( new TaxonomyDiscount() )->get_settings();

		self::assertSame(
			0,
			$this->wp_count_terms_calls,
			'wp_count_terms cuenta filas de la base que un filtro de get_terms puede no devolver: '
			. 'el acordeón terminaba anunciando términos que no estaban dentro, y encima costaba '
			. 'una consulta por taxonomía.'
		);
	}

	/**
	 * Stubs mínimos de WordPress.
	 */
	private function stub_wordpress(): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => in_array( $taxonomy, self::TAXONOMIES, true )
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null ) => $value
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );
		// `Settings::taxonomies()` cae en esta rama cuando
		// `function_exists( 'wc_get_attribute_taxonomies' )` da true, y eso ocurre
		// por CONTAGIO: basta con que otra prueba de la suite la haya definido
		// antes. Sin este stub el resultado dependía del orden — verde en el orden
		// natural, rojo con `--order-by=random`.
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = '' ) => $default
		);
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id ) => 101 === $term_id ? '10' : ''
		);
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_count_terms' )->alias(
			function ( array $args ) {
				++$this->wp_count_terms_calls;

				return count( $this->fake_terms( (string) ( $args['taxonomy'] ?? '' ) ) );
			}
		);
		Functions\when( 'get_terms' )->alias(
			function ( array $args ) {
				$this->get_terms_calls[] = $args;

				$terms = $this->fake_terms( (string) ( $args['taxonomy'] ?? '' ) );

				// `fields => ids` es una forma DISTINTA de la misma pregunta: WP no
				// la resuelve desde el caché de la consulta completa, así que
				// mezclarlas era una consulta extra por taxonomía.
				if ( 'ids' === ( $args['fields'] ?? '' ) ) {
					return array_map( static fn( object $term ): int => (int) $term->term_id, $terms );
				}

				return $terms;
			}
		);
	}

	/**
	 * @return array<int, object>
	 */
	private function fake_terms( string $taxonomy ): array {
		$base = 'product_brand' === $taxonomy ? 100 : 200;

		$terms = array();
		foreach ( array( 'Ivrea', 'Panini', 'Distrito Manga' ) as $index => $name ) {
			$term          = new \stdClass();
			$term->term_id = $base + $index + 1;
			$term->name    = $name;
			$term->count   = 3;
			$terms[]       = $term;
		}

		return $terms;
	}
}
