<?php
/**
 * Candado: la lista de términos de `pa_serie` muestra el estado de publicación.
 *
 * La configuración de una serie vive repartida en cuatro pantallas —estado de
 * publicación y ediciones en el término, tramos de «Arma tu Pack» en el panel de
 * Akibara— y ninguna enlazaba a las otras. Peor: la lista de términos, la única
 * que muestra las 115 series de una vez, no decía NADA de su estado, así que
 * saber cuáles faltaban por cargar obligaba a abrirlas una por una.
 *
 * INVARIANTES: la lista trae una columna con el estado de la edición vendida, y
 * la pantalla de edición enlaza a la otra pantalla que configura la misma serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFacts;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesFacts\PublicationStatusFields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PublicationStatusColumnsTest extends TestCase {

	/** term_id => [meta_key => valor]. */
	private array $term_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->term_meta = array();

		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key ) => $this->term_meta[ $term_id ][ $key ] ?? ''
		);
		Functions\when( 'esc_html' )->alias( static fn( $text ): string => (string) $text );
		// S-59834769: la columna ahora compone su texto con `edition_phrase()`,
		// que formatea el total con `number_format_i18n()` igual que la landing.
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'esc_attr' )->alias( static fn( $text ): string => (string) $text );
		Functions\when( 'esc_url' )->alias( static fn( $url ): string => (string) $url );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox La columna propia se inserta después del nombre y no pisa ninguna.
	 */
	public function test_column_is_added_after_the_name(): void {
		$columns = ( new PublicationStatusFields() )->handle_admin_columns(
			array(
				'cb'    => '<input type="checkbox">',
				'name'  => 'Nombre',
				'slug'  => 'Slug',
				'posts' => 'Cuenta',
			)
		);

		self::assertSame(
			array( 'cb', 'name', 'akb_publicacion', 'slug', 'posts' ),
			array_keys( $columns )
		);
		self::assertSame( 'Nombre', $columns['name'], 'Las columnas ajenas quedan intactas.' );
	}

	/**
	 * @testdox Una serie sin estado se ve como pendiente, no como vacía.
	 */
	public function test_series_without_state_render_as_pending(): void {
		$content = ( new PublicationStatusFields() )->handle_admin_column_content( '', 'akb_publicacion', 9 );

		self::assertStringContainsString( 'Sin definir', $content );
	}

	/**
	 * @testdox La celda muestra el estado de la EDICIÓN vendida y su total.
	 */
	public function test_cell_shows_the_edition_state_and_total(): void {
		$this->term_meta[9] = array(
			PublicationStatus::META_EDITION_STATE => 'completa',
			PublicationStatus::META_EDITION_TOTAL => 8,
		);

		$content = ( new PublicationStatusFields() )->handle_admin_column_content( '', 'akb_publicacion', 9 );
		$labels  = PublicationStatus::edition_states();

		self::assertStringContainsString( (string) ( $labels['completa'] ?? 'completa' ), $content );
		self::assertStringContainsString( '8 tomos', $content );
	}

	/**
	 * S-59834769 (2026-09-19): la columna independiente que calculaba
	 * `$total > 0` a secas mostraba «Al día · 8 tomos» aunque
	 * `PublicationStatus::edition_phrase()` —la MISMA función que usa la
	 * landing— nunca publica un total para ese estado. El dueño tecleaba un
	 * número que confirmaba en el listado y desaparecía sin aviso en las 104
	 * landings.
	 *
	 * @testdox La celda NO promete un total que la landing no publica («Al día»).
	 */
	public function test_cell_no_muestra_un_total_que_la_landing_no_publica(): void {
		$this->term_meta[9] = array(
			PublicationStatus::META_EDITION_STATE => PublicationStatus::UP_TO_DATE,
			PublicationStatus::META_EDITION_TOTAL => 8,
		);

		$content = ( new PublicationStatusFields() )->handle_admin_column_content( '', 'akb_publicacion', 9 );

		self::assertStringNotContainsString(
			'8 tomos',
			$content,
			'La columna prometía un total que la landing calla para "Al día".'
		);
		self::assertStringContainsString( 'Al día', $content );
	}

	/**
	 * @testdox Otras columnas no se tocan.
	 */
	public function test_other_columns_are_left_alone(): void {
		$content = ( new PublicationStatusFields() )->handle_admin_column_content( 'intacto', 'slug', 9 );

		self::assertSame( 'intacto', $content );
	}

	/**
	 * @testdox La pantalla de edición enlaza al panel que configura la misma serie.
	 */
	public function test_edit_screen_links_to_the_bundles_panel(): void {
		$term           = new \stdClass();
		$term->term_id  = 9;

		ob_start();
		( new PublicationStatusFields() )->handle_edit_form_fields( $term, 'pa_serie' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'page=akibara-panel', $html );
		self::assertStringContainsString( 'tab=series_bundles', $html );
		// Sin fragmento: el `<details>` de cada serie no lleva `id`, y enlazar a un
		// ancla inexistente hace que el navegador ignore el salto en silencio.
		self::assertStringNotContainsString( '#akb-sb-series', $html );
	}
}
