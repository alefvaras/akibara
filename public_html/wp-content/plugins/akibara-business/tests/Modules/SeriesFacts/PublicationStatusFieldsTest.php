<?php
/**
 * Pruebas de la UI del término que alimenta el renglón de publicación.
 *
 * Lo que se protege acá no es el formulario: es que `edited_pa_serie` lo dispara
 * CUALQUIER `wp_update_term()` —una importación, un cron, otro plugin—, y en ese
 * camino no hay nonce, no hay formulario y el usuario efectivo puede ser
 * cualquiera. Los tres guards y el "ausente ≠ vacío" son lo que impide que ese
 * camino borre el trabajo manual de 104 series.
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

final class PublicationStatusFieldsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_post = array();

	/** @var array<int, array<int, mixed>> Escrituras observadas: [op, term, key, value]. */
	private array $writes = array();

	/** @var array<string, mixed> Lo que `get_term_meta()` devuelve por clave. */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$_POST               = array();
		$this->writes        = array();
		$this->meta          = array();

		// Defaults deterministas del camino feliz. No son opcionales: Brain Monkey
		// deja el símbolo definido tras el tearDown, así que sin esto un test que
		// mockee `current_user_can` decidiría el resultado de los demás según el
		// orden — verde en orden natural, rojo con --order-by=random.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( 12 );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		// El formulario de edición cierra con un enlace al panel que configura la
		// MISMA serie (los tramos de «Arma tu Pack» viven en otra pantalla y antes
		// nada las enlazaba). `function_exists()` no basta como guardia acá: Brain
		// Monkey deja el símbolo definido y lo que revienta es la llamada.
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		Functions\when( 'get_term_meta' )->alias(
			function ( int $term_id, string $key, bool $single = false ) {
				unset( $term_id, $single );

				return $this->meta[ $key ] ?? '';
			}
		);
		// Las escrituras se OBSERVAN y además se aplican sobre `$this->meta`: el
		// guardado relee el término para saber si algo cambió (y sólo entonces
		// purga la caché de página), así que un doble que no reflejara la escritura
		// haría pasar por «no cambió nada» justamente el caso que sí cambia.
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $value ): bool {
				$this->writes[]      = array( 'update', $term_id, $key, $value );
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $term_id, string $key ): bool {
				$this->writes[] = array( 'delete', $term_id, $key );
				unset( $this->meta[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/the-climber/' );
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** POST completo del formulario, con el nonce que el guard espera. */
	private function post( string $state, string $total ): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_estado'            => $state,
			'akb_serie_tomos_obra'        => $total,
		);
	}

	/** POST completo de ambos ámbitos. */
	private function post_all( string $edition_state, string $edition_total, string $work_state, string $work_total ): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_estado_edicion'    => $edition_state,
			'akb_serie_tomos_edicion'     => $edition_total,
			'akb_serie_estado'            => $work_state,
			'akb_serie_tomos_obra'        => $work_total,
		);
	}

	/**
	 * Los hooks nativos de la taxonomía y termmeta. Sin los de render el dueño no
	 * tiene dónde escribir el dato; sin los de guardado, lo que escriba se pierde.
	 *
	 * @testdox Should hook term forms plus direct term-meta mutations.
	 */
	public function test_registers_term_form_and_direct_meta_hooks(): void {
		$module = new PublicationStatusFields();
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )
				->once()
				->with( array( $module, 'handle_publication_meta_changed' ), 10, 3 );
		}
		$module->register();

		foreach ( array(
			'pa_serie_add_form_fields'  => 'handle_add_form_fields',
			'pa_serie_edit_form_fields' => 'handle_edit_form_fields',
			'created_pa_serie'          => 'handle_save_term',
			'edited_pa_serie'           => 'handle_save_term',
			'added_term_meta'           => 'handle_publication_meta_changed',
			'updated_term_meta'         => 'handle_publication_meta_changed',
			'deleted_term_meta'         => 'handle_publication_meta_changed',
		) as $hook => $method ) {
			self::assertNotFalse(
				Monkey\Actions\has( $hook, array( $module, $method ) ),
				sprintf( 'Sin %s el campo no existe o no persiste.', $hook )
			);
		}
	}

	/**
	 * @testdox Should persist both fields for an authorized term edit.
	 */
	public function test_saves_both_fields(): void {
		$this->post( PublicationStatus::COMPLETE, '23' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array(
				array( 'update', 12, PublicationStatus::META_WORK_STATE, PublicationStatus::COMPLETE ),
				array( 'update', 12, PublicationStatus::META_WORK_TOTAL, 23 ),
			),
			$this->writes
		);
	}

	/**
	 * @testdox Should save edition first and work second in one authorized edit.
	 */
	public function test_saves_both_publication_scopes(): void {
		$this->post_all( PublicationStatus::ONGOING, '8', PublicationStatus::COMPLETE, '' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array(
				array( 'update', 12, PublicationStatus::META_EDITION_STATE, PublicationStatus::ONGOING ),
				array( 'update', 12, PublicationStatus::META_EDITION_TOTAL, 8 ),
				array( 'update', 12, PublicationStatus::META_WORK_STATE, PublicationStatus::COMPLETE ),
				array( 'delete', 12, PublicationStatus::META_WORK_TOTAL ),
			),
			$this->writes
		);
	}

	/**
	 * @testdox Should accept the edition-only up-to-date state without changing work data.
	 */
	public function test_saves_the_edition_only_up_to_date_state(): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_estado_edicion'    => PublicationStatus::UP_TO_DATE,
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array( array( 'update', 12, PublicationStatus::META_EDITION_STATE, PublicationStatus::UP_TO_DATE ) ),
			$this->writes
		);
	}

	/**
	 * El total se guarda como ENTERO, no como la cadena del `<input>`: quien lo
	 * lea después compara contra los tomos del catálogo, que sí es un entero.
	 *
	 * @testdox Should store the total as an integer, not as the raw input string.
	 */
	public function test_stores_the_total_as_an_integer(): void {
		$this->post( PublicationStatus::COMPLETE, ' 23 ' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( 23, $this->writes[1][3] );
	}

	/**
	 * `edited_pa_serie` lo dispara cualquier `wp_update_term()`. Sin nonce no hay
	 * formulario detrás y no hay nada que guardar.
	 *
	 * @testdox Should write nothing when the nonce does not verify.
	 */
	public function test_writes_nothing_without_a_valid_nonce(): void {
		$this->post( PublicationStatus::COMPLETE, '23' );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( array(), $this->writes );
	}

	/**
	 * La capability es la de la taxonomía (`manage_product_terms`), no
	 * `manage_categories`: es la que WooCommerce registra para los atributos de
	 * producto y la misma que ya verifica `SeriesRelatedGuide` sobre este mismo
	 * formulario.
	 *
	 * @testdox Should require the WooCommerce product-term capability.
	 */
	public function test_requires_the_product_term_capability(): void {
		$this->post( PublicationStatus::COMPLETE, '23' );

		// `when()->alias()` y no `expect()->with()`: el default determinista de
		// setUp ya registró un `when()` para esta función, y Brain Monkey no deja
		// que un `expect()` posterior lo reemplace en el mismo test. Capturar el
		// argumento comprueba lo mismo —QUÉ capability se exige— sin el choque.
		$asked = '';
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$asked ): bool {
				$asked = $capability;
				return false;
			}
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( 'manage_product_terms', $asked );
		self::assertSame( array(), $this->writes );
	}

	/**
	 * @testdox Should write nothing for a term that is not one of this taxonomy.
	 */
	public function test_writes_nothing_for_a_term_of_another_taxonomy(): void {
		$this->post( PublicationStatus::COMPLETE, '23' );

		// El segundo argumento es lo que se comprueba: sin acotar a `pa_serie`,
		// `edited_pa_serie` seguiría siendo el hook pero el guard aceptaría un id
		// que existe en cualquier otra taxonomía.
		$asked = array();
		Functions\when( 'term_exists' )->alias(
			static function ( $term, string $taxonomy = '' ) use ( &$asked ) {
				$asked = array( $term, $taxonomy );
				return null;
			}
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( array( 12, 'pa_serie' ), $asked );
		self::assertSame( array(), $this->writes );
	}

	/**
	 * Elegir "— Sin definir —" devuelve la landing a no decir nada, que es un
	 * estado legítimo y el inicial de las 104 series.
	 *
	 * @testdox Should delete the meta when the owner clears the field.
	 */
	public function test_clearing_the_field_deletes_the_meta(): void {
		$this->post( '', '' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array(
				array( 'delete', 12, PublicationStatus::META_WORK_STATE ),
				array( 'delete', 12, PublicationStatus::META_WORK_TOTAL ),
			),
			$this->writes
		);
	}

	/**
	 * Un valor fuera del enum no se guarda "por si acaso": dejaría en
	 * `wp_termmeta` algo que ninguna superficie sabe leer y que el formulario
	 * tampoco puede volver a mostrar.
	 *
	 * @testdox Should never store a state outside the closed set.
	 */
	public function test_never_stores_a_state_outside_the_set(): void {
		$this->post( 'terminada', '23' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		$state_writes = array_values(
			array_filter(
				$this->writes,
				static fn( array $write ): bool => PublicationStatus::META_WORK_STATE === $write[2]
			)
		);

		self::assertSame( array( array( 'delete', 12, PublicationStatus::META_WORK_STATE ) ), $state_writes );
	}

	/**
	 * Ausente ≠ vacío. Si otra pantalla guarda el término con un formulario que
	 * no incluya estos inputs, un guardado ciego los leería como "vacío" y
	 * borraría el trabajo manual de 104 series sin que nadie se entere.
	 *
	 * @testdox Should leave stored values alone when the form did not carry the fields.
	 */
	public function test_absent_fields_are_not_the_same_as_empty_ones(): void {
		$_POST = array( 'akb_serie_publicacion_nonce' => 'valid' );

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( array(), $this->writes );
	}

	/**
	 * Un campo presente y el otro ausente se resuelven por separado: guardar el
	 * estado no puede arrastrarse el total.
	 *
	 * @testdox Should resolve each field independently.
	 */
	public function test_resolves_each_field_independently(): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_estado'            => PublicationStatus::ONGOING,
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array( array( 'update', 12, PublicationStatus::META_WORK_STATE, PublicationStatus::ONGOING ) ),
			$this->writes
		);
	}

	/**
	 * @testdox Should resolve every edition field independently from work fields.
	 */
	public function test_resolves_each_edition_field_independently(): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_tomos_edicion'     => '8',
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame(
			array( array( 'update', 12, PublicationStatus::META_EDITION_TOTAL, 8 ) ),
			$this->writes
		);
	}

	/**
	 * `campo[]=x` es la única forma de llegar acá que NO puede venir de este
	 * formulario. Ante algo que no entendemos, la respuesta es dejar el dato como
	 * está, no borrarlo.
	 *
	 * @testdox Should treat a non-scalar submission as absent, never as a clear.
	 */
	public function test_a_non_scalar_submission_is_absent_not_empty(): void {
		$_POST = array(
			'akb_serie_publicacion_nonce' => 'valid',
			'akb_serie_estado'            => array( PublicationStatus::COMPLETE ),
			'akb_serie_tomos_obra'        => array( '23' ),
		);

		( new PublicationStatusFields() )->handle_save_term( 12 );

		self::assertSame( array(), $this->writes );
	}

	/**
	 * El formulario ofrece el conjunto cerrado y marca lo guardado. Un `<select>`
	 * y no un campo de texto es lo que hace cerrado el conjunto también para
	 * quien lo llena.
	 *
	 * @testdox Should offer the closed set and preselect what is stored.
	 */
	public function test_the_edit_form_offers_the_closed_set(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => PublicationStatus::PAUSED,
			PublicationStatus::META_WORK_TOTAL => '42',
		);
		$term       = (object) array( 'term_id' => 12 );

		ob_start();
		( new PublicationStatusFields() )->handle_edit_form_fields( $term, 'pa_serie' );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'type="text"', $html, 'Texto libre no: el valor es cerrado.' );
		self::assertStringContainsString( '<option value="">— Sin definir —</option>', $html );
		foreach ( PublicationStatus::states() as $value => $label ) {
			self::assertStringContainsString( sprintf( 'value="%s"', $value ), $html );
			self::assertStringContainsString( $label, $html );
		}
		self::assertStringContainsString(
			sprintf( 'value="%s" selected', PublicationStatus::PAUSED ),
			$html,
			'Lo guardado llega marcado.'
		);
		self::assertStringContainsString( 'value="42"', $html );
	}

	/**
	 * @testdox Should render edition fields before legacy-compatible work fields.
	 */
	public function test_the_edit_form_renders_edition_before_work(): void {
		$this->meta = array(
			PublicationStatus::META_EDITION_STATE => PublicationStatus::UP_TO_DATE,
			PublicationStatus::META_EDITION_TOTAL => 8,
			PublicationStatus::META_WORK_STATE    => PublicationStatus::COMPLETE,
			PublicationStatus::META_WORK_TOTAL    => 193,
		);

		ob_start();
		( new PublicationStatusFields() )->handle_edit_form_fields( (object) array( 'term_id' => 12 ), 'pa_serie' );
		$html = (string) ob_get_clean();

		self::assertLessThan(
			strpos( $html, 'name="akb_serie_estado"' ),
			strpos( $html, 'name="akb_serie_estado_edicion"' ),
			'La edición debe aparecer antes que la obra original.'
		);
		self::assertStringContainsString( 'value="al_dia" selected', $html );
		self::assertStringContainsString( 'value="8"', $html );
		self::assertStringContainsString( 'value="completa" selected', $html );
		self::assertStringContainsString( 'value="193"', $html );
	}

	/**
	 * Si algo guardado fuera del enum no se va a publicar, el formulario tampoco
	 * puede mostrarlo como si estuviera seleccionado: el dueño creería que la
	 * serie ya está resuelta.
	 *
	 * @testdox Should not preselect a stored value that the frontend would refuse.
	 */
	public function test_the_edit_form_does_not_preselect_an_unpublishable_value(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => 'finalizada',
			PublicationStatus::META_WORK_TOTAL => '99999',
		);
		$term       = (object) array( 'term_id' => 12 );

		ob_start();
		( new PublicationStatusFields() )->handle_edit_form_fields( $term, 'pa_serie' );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( ' selected', $html );
		self::assertStringContainsString( 'value=""', $html, 'El número inválido deja el campo vacío.' );
	}

	/**
	 * @testdox Should print nothing for a term of another taxonomy.
	 */
	public function test_the_edit_form_ignores_other_taxonomies(): void {
		$term = (object) array( 'term_id' => 12 );

		ob_start();
		( new PublicationStatusFields() )->handle_edit_form_fields( $term, 'pa_autor' );

		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should render the create form with both fields and no stored value.
	 */
	public function test_the_add_form_renders_both_fields_empty(): void {
		ob_start();
		( new PublicationStatusFields() )->handle_add_form_fields();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="akb_serie_estado"', $html );
		self::assertStringContainsString( 'name="akb_serie_tomos_obra"', $html );
		self::assertStringContainsString( 'name="akb_serie_estado_edicion"', $html );
		self::assertStringContainsString( 'name="akb_serie_tomos_edicion"', $html );
		self::assertStringContainsString( 'obra original', $html );
		self::assertStringContainsString( 'No es la cantidad disponible en Akibara', $html );
		self::assertStringContainsString( 'alcanzó todo el material publicado de una obra aún no finalizada', $html );
		self::assertStringNotContainsString( 'que aún continúa', $html );
		self::assertStringNotContainsString( ' selected', $html );
	}

	// -------------------------------------------------------------------------
	// Purga de la caché de PÁGINA
	// -------------------------------------------------------------------------

	/**
	 * Registra las purgas que dispare el guardado, sin decidir cuántas espera.
	 *
	 * `expectDone()->once()` bastaría para verificar, pero PHPUnit no cuenta las
	 * expectativas de Mockery como aserciones y esta suite corre con
	 * `failOnRisky="true"`: un test que sólo las use se reporta como riesgoso.
	 * Grabando lo que pasó, cada prueba afirma sobre datos.
	 *
	 * @return array<int, array{hook:string, url:mixed}> Referencia viva.
	 */
	private function &record_purges(): array {
		$purges = array();

		foreach ( array( 'litespeed_purge', 'litespeed_purge_all' ) as $hook ) {
			Monkey\Actions\expectDone( $hook )
				->zeroOrMoreTimes()
				->whenHappen(
					static function ( $url = null ) use ( &$purges, $hook ): void {
						$purges[] = array(
							'hook' => $hook,
							'url'  => $url,
						);
					}
				);
		}

		return $purges;
	}

	/**
	 * El módulo esquivó con cuidado el transient de 6 h de `SeriesFacts` para que
	 * un dato recién escrito saliera al instante, y el mismo retardo reaparecía una
	 * capa más arriba: la landing se sirve desde la caché de página de LiteSpeed
	 * (`x-litespeed-cache: hit` en 6 de 7 peticiones al origen, medido el
	 * 2026-08-09) y LiteSpeed sólo purga por eventos de POST — `purge.cls.php`
	 * engancha `delete_post`, `wp_trash_post`, `wp_update_comment_count` y
	 * `transition_post_status`, ninguno de los cuales dispara al guardar un
	 * TÉRMINO. Sin esto, el dueño carga las 104 series de una sentada y no ve
	 * aparecer ni una: la forma exacta de parecer roto sin estarlo que el docblock
	 * de `PublicationStatus` dice haber evitado.
	 *
	 * Y la etiqueta del término, no `litespeed_purge_all`: acá cambia UNA serie, y esa
	 * carga es una sesión seguida de guardados — con `purge_all` cada uno dejaría
	 * el sitio entero sin caché, 104 veces. La etiqueta `T.<id>` cubre la URL base
	 * y todas sus páginas indexables sin tocar las otras 103 series.
	 *
	 * @testdox Should purge every page of this series when the datum actually changed.
	 */
	public function test_saving_a_new_value_purges_the_landing(): void {
		$purges = &$this->record_purges();

		$this->post( PublicationStatus::COMPLETE, '23' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		self::assertSame( array(), $purges, 'La purga espera el estado final del request.' );
		$module->flush_pending_landing_purges();

		self::assertSame(
			array(
				array(
					'hook' => 'litespeed_purge',
					'url'  => 'T.12',
				),
			),
			$purges
		);
	}

	/**
	 * @testdox Should purge the landing only once when all four effective values change.
	 */
	public function test_saving_both_scopes_purges_once(): void {
		$purges = &$this->record_purges();
		$this->post_all( PublicationStatus::ONGOING, '8', PublicationStatus::COMPLETE, '' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertSame(
			array(
				array(
					'hook' => 'litespeed_purge',
					'url'  => 'T.12',
				),
			),
			$purges
		);
	}

	/**
	 * La etiqueta del término sigue siendo purgable aunque el término se haya
	 * eliminado antes de shutdown; no hay razón para enfriar todo el sitio.
	 *
	 * @testdox Should retain a narrow term purge after the term stops resolving.
	 */
	public function test_an_unresolvable_term_link_keeps_the_narrow_term_purge(): void {
		Functions\when( 'get_term_link' )->justReturn( false );
		$purges = &$this->record_purges();

		$this->post( PublicationStatus::COMPLETE, '23' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertCount( 1, $purges );
		self::assertSame( array( 'hook' => 'litespeed_purge', 'url' => 'T.12' ), $purges[0] );
	}

	/**
	 * `edited_pa_serie` lo dispara CUALQUIER guardado del término —corregir la
	 * descripción, el slug— y el formulario reenvía estos dos campos con el mismo
	 * valor. Purgar ahí sería tirar la copia cacheada de la landing por un dato que
	 * no cambió.
	 *
	 * @testdox Should not purge when the resubmitted values are identical.
	 */
	public function test_resaving_the_same_values_purges_nothing(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => PublicationStatus::COMPLETE,
			PublicationStatus::META_WORK_TOTAL => 23,
		);
		$purges     = &$this->record_purges();

		$this->post( PublicationStatus::COMPLETE, '23' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertSame( array(), $purges );
	}

	/**
	 * @testdox Should not purge when both publication scopes are resubmitted unchanged.
	 */
	public function test_resaving_both_scopes_purges_nothing(): void {
		$this->meta = array(
			PublicationStatus::META_EDITION_STATE => PublicationStatus::ONGOING,
			PublicationStatus::META_EDITION_TOTAL => 8,
			PublicationStatus::META_WORK_STATE    => PublicationStatus::COMPLETE,
		);
		$purges = &$this->record_purges();
		$this->post_all( PublicationStatus::ONGOING, '8', PublicationStatus::COMPLETE, '' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertSame( array(), $purges );
	}

	/**
	 * Y un guardado que los guards rechazan (nonce ausente, importación, cron) no
	 * escribe nada, así que tampoco puede purgar nada.
	 *
	 * @testdox Should not purge when the save itself was refused.
	 */
	public function test_a_refused_save_purges_nothing(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$purges = &$this->record_purges();

		$this->post( PublicationStatus::COMPLETE, '23' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertSame( array(), $this->writes );
		self::assertSame( array(), $purges );
	}

	/**
	 * Borrar el dato también cambia la landing: el renglón desaparece, y la copia
	 * cacheada seguiría publicándolo.
	 *
	 * @testdox Should purge the landing when the datum is cleared.
	 */
	public function test_clearing_the_datum_purges_the_landing(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => PublicationStatus::COMPLETE,
			PublicationStatus::META_WORK_TOTAL => 23,
		);
		$purges     = &$this->record_purges();

		$this->post( '', '' );

		$module = new PublicationStatusFields();
		$module->handle_save_term( 12 );
		$module->flush_pending_landing_purges();

		self::assertCount( 1, $purges );
		self::assertSame( 'litespeed_purge', $purges[0]['hook'] );
	}

	/** @testdox Should coalesce all four direct publication-meta mutations into one late purge. */
	public function test_direct_api_meta_changes_purge_the_landing_once_at_shutdown(): void {
		$purges = &$this->record_purges();
		$module = new PublicationStatusFields();
		Monkey\Actions\expectAdded( 'shutdown' )
			->once()
			->with( array( $module, 'flush_pending_landing_purges' ), PHP_INT_MAX );

		foreach ( array(
			PublicationStatus::META_WORK_STATE,
			PublicationStatus::META_WORK_TOTAL,
			PublicationStatus::META_EDITION_STATE,
			PublicationStatus::META_EDITION_TOTAL,
		) as $meta_key ) {
			$module->handle_publication_meta_changed( 99, 12, $meta_key );
		}
		$module->handle_publication_meta_changed( 99, 12, 'rank_math_robots' );

		self::assertSame( array(), $purges );
		$module->flush_pending_landing_purges();
		$module->flush_pending_landing_purges();

		self::assertSame( array( array( 'hook' => 'litespeed_purge', 'url' => 'T.12' ) ), $purges );
	}

	/** @testdox Should purge direct Rank Math and related-guide metadata without depending on a term form save. */
	public function test_direct_public_landing_meta_changes_purge_once_at_shutdown(): void {
		$purges = &$this->record_purges();
		$module = new PublicationStatusFields();
		Monkey\Actions\expectAdded( 'shutdown' )
			->once()
			->with( array( $module, 'flush_pending_landing_purges' ), PHP_INT_MAX );

		foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_canonical_url', '_akb_related_guide_post_id' ) as $meta_key ) {
			$module->handle_publication_meta_changed( 99, 12, $meta_key );
		}

		$module->flush_pending_landing_purges();

		self::assertSame( array( array( 'hook' => 'litespeed_purge', 'url' => 'T.12' ) ), $purges );
	}

	/** @testdox Should ignore publication-looking metadata on a term outside pa_serie. */
	public function test_direct_meta_hook_rejects_a_term_outside_the_series_taxonomy(): void {
		Functions\when( 'term_exists' )->justReturn( null );
		$purges = &$this->record_purges();
		$module = new PublicationStatusFields();

		$module->handle_publication_meta_changed( 99, 12, PublicationStatus::META_WORK_STATE );
		$module->flush_pending_landing_purges();

		self::assertSame( array(), $purges );
	}
}
