<?php
/**
 * Clasificar una serie como cómic o no es una decisión del dueño, y tiene que
 * poder tomarla en pantalla.
 *
 * Hallazgo S-ccf4dde8: `_akb_serie_schema_type` gobierna el tipo declarado en
 * los datos estructurados de la landing de la serie y de las fichas de todos sus
 * tomos, y sólo se podía escribir por SQL o WP-CLI.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesSchema;

use Akibara\Business\Modules\SeriesSchema\SchemaTypeFields;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ClasificarSerieEnPantallaTest extends TestCase {

	private const TERM_ID = 77;

	/** @var array<string, string> */
	private array $meta = array();

	/** @var array<int, string> */
	private array $purgas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta   = array();
		$this->purgas = array();
		$_POST        = array();

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce ): bool => 'bueno' === $nonce );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( array( 'term_id' => self::TERM_ID ) );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key ) => $this->meta[ $key ] ?? ''
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $valor ): bool {
				$this->meta[ $key ] = (string) $valor;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $term_id, string $key ): bool {
				unset( $this->meta[ $key ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook ): void {
				$this->purgas[] = $hook;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Elegir «no es cómic» guarda la clasificación y limpia la caché. */
	public function test_saving_non_comic_persists_and_purges(): void {
		$_POST['akb_serie_tipo_schema_nonce'] = 'bueno';
		$_POST['akb_serie_tipo_schema']       = SeriesSchemaType::NON_COMIC;

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertSame( SeriesSchemaType::NON_COMIC, $this->meta[ SeriesSchemaType::META_KEY ] ?? '' );
		self::assertFalse( SeriesSchemaType::supports_comic_series( self::TERM_ID ) );
		self::assertContains( 'litespeed_purge_all', $this->purgas, 'El JSON-LD cambia en la landing Y en las fichas de sus tomos.' );
	}

	/** @testdox Volver a «sin definir» borra la meta, la serie vuelve a ser cómic y eso sí limpia la caché. */
	public function test_clearing_the_value_removes_the_meta(): void {
		$this->meta[ SeriesSchemaType::META_KEY ] = SeriesSchemaType::NON_COMIC;
		$_POST['akb_serie_tipo_schema_nonce']     = 'bueno';
		$_POST['akb_serie_tipo_schema']           = '';

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertArrayNotHasKey( SeriesSchemaType::META_KEY, $this->meta );
		self::assertTrue( SeriesSchemaType::supports_comic_series( self::TERM_ID ) );
		self::assertContains( 'litespeed_purge_all', $this->purgas, 'Sale de «no es cómic»: el JSON-LD de la landing y de sus tomos cambia.' );
	}

	/**
	 * Hallazgo de la revisión de 1.116.8: «sin definir» y «Cómic o manga»
	 * publican el mismo JSON-LD, y cada guardado entre los dos vaciaba la caché
	 * del sitio entero. Marcar las series una a una eran N purgas totales sin un
	 * byte distinto.
	 *
	 * @testdox Pasar de «sin definir» a «Cómic o manga» guarda la elección y NO purga el sitio.
	 */
	public function test_marking_an_undefined_series_as_comic_saves_without_purging(): void {
		$_POST['akb_serie_tipo_schema_nonce'] = 'bueno';
		$_POST['akb_serie_tipo_schema']       = SeriesSchemaType::COMIC_SERIES;

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertSame( SeriesSchemaType::COMIC_SERIES, $this->meta[ SeriesSchemaType::META_KEY ] ?? '' );
		self::assertSame( array(), $this->purgas );
	}

	/** @testdox Y al revés: de «Cómic o manga» a «sin definir» se borra la meta sin purgar. */
	public function test_clearing_an_explicit_comic_series_does_not_purge(): void {
		$this->meta[ SeriesSchemaType::META_KEY ] = SeriesSchemaType::COMIC_SERIES;
		$_POST['akb_serie_tipo_schema_nonce']     = 'bueno';
		$_POST['akb_serie_tipo_schema']           = '';

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertArrayNotHasKey( SeriesSchemaType::META_KEY, $this->meta );
		self::assertSame( array(), $this->purgas );
	}

	/** @testdox De «Cómic o manga» a «no es cómic» sí cambia lo publicado y sí purga. */
	public function test_leaving_comic_for_non_comic_purges(): void {
		$this->meta[ SeriesSchemaType::META_KEY ] = SeriesSchemaType::COMIC_SERIES;
		$_POST['akb_serie_tipo_schema_nonce']     = 'bueno';
		$_POST['akb_serie_tipo_schema']           = SeriesSchemaType::NON_COMIC;

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertSame( SeriesSchemaType::NON_COMIC, $this->meta[ SeriesSchemaType::META_KEY ] ?? '' );
		self::assertContains( 'litespeed_purge_all', $this->purgas );
	}

	/**
	 * Una meta fuera de la lista se pinta «sin definir», pero el frontend la
	 * lee como «no es cómic» (falla cerrado). Guardar «sin definir» sobre ella
	 * tiene que borrarla de verdad, y como la serie vuelve a ser cómic, purgar.
	 *
	 * @testdox Elegir «sin definir» sobre una meta inválida la borra y purga.
	 */
	public function test_choosing_undefined_over_an_invalid_value_cleans_it_up(): void {
		$this->meta[ SeriesSchemaType::META_KEY ] = 'novela';
		$_POST['akb_serie_tipo_schema_nonce']     = 'bueno';
		$_POST['akb_serie_tipo_schema']           = '';

		self::assertFalse( SeriesSchemaType::supports_comic_series( self::TERM_ID ), 'Precondición: la basura falla cerrado.' );

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertArrayNotHasKey( SeriesSchemaType::META_KEY, $this->meta );
		self::assertTrue( SeriesSchemaType::supports_comic_series( self::TERM_ID ) );
		self::assertContains( 'litespeed_purge_all', $this->purgas );
	}

	/** @testdox Sin nonce no se guarda nada: `edited_pa_serie` lo dispara cualquier importación. */
	public function test_without_a_nonce_nothing_is_written(): void {
		$_POST['akb_serie_tipo_schema'] = SeriesSchemaType::NON_COMIC;

		( new SchemaTypeFields() )->handle_save_term( self::TERM_ID );

		self::assertSame( array(), $this->meta );
	}

	/** @testdox Un valor fuera de la lista no reclasifica la serie. */
	public function test_an_unknown_value_is_ignored(): void {
		$_POST['akb_serie_tipo_schema_nonce'] = 'bueno';
		$_POST['akb_serie_tipo_schema']       = 'novela_grafica';

		( new SchemaTypeFields() )->handle_save_term( self::TERM_ID );

		self::assertSame( array(), $this->meta );
	}

	/** @testdox Guardar el término sin tocar el tipo no purga nada. */
	public function test_saving_the_same_value_does_not_purge(): void {
		$this->meta[ SeriesSchemaType::META_KEY ] = SeriesSchemaType::COMIC_SERIES;
		$_POST['akb_serie_tipo_schema_nonce']     = 'bueno';
		$_POST['akb_serie_tipo_schema']           = SeriesSchemaType::COMIC_SERIES;

		$campos = new SchemaTypeFields();
		$campos->handle_save_term( self::TERM_ID );
		$campos->flush_pending_purges();

		self::assertSame( array(), $this->purgas );
	}

	/** @testdox La lista de series dice de un vistazo qué es cada una. */
	public function test_the_column_says_what_the_series_is(): void {
		$campos = new SchemaTypeFields();

		self::assertSame( 'Cómic o manga', $campos->handle_admin_column_content( '', 'akb_tipo_schema', self::TERM_ID ) );

		$this->meta[ SeriesSchemaType::META_KEY ] = SeriesSchemaType::NON_COMIC;
		self::assertSame( 'No es cómic', $campos->handle_admin_column_content( '', 'akb_tipo_schema', self::TERM_ID ) );

		self::assertSame( 'otra', $campos->handle_admin_column_content( 'otra', 'columna_ajena', self::TERM_ID ) );
	}
}
