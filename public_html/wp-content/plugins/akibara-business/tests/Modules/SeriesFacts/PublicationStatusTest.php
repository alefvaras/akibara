<?php
/**
 * Pruebas del contrato del estado de publicación de una serie.
 *
 * Es el ÚNICO dato de `SeriesFacts` que no se deriva del catálogo, así que todo
 * lo que lo protege está acá: el conjunto cerrado, el saneo al leer y al
 * escribir, y las tres formas de callar un número que no se puede afirmar.
 *
 * ── Por qué setUp() declara `get_term_meta` y `number_format_i18n` ───────────
 * Brain Monkey define la función en el runtime la primera vez que un test la
 * mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`. Sin defaults acá,
 * `for_term()` recibiría el stub vacío que dejó otra suite —null— y los asserts
 * pasarían o fallarían según qué corriera antes: verde en orden natural, rojo
 * con `--order-by=random`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFacts;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PublicationStatusTest extends TestCase {

	/** @var array<string, mixed> Lo que `get_term_meta()` devuelve por clave. */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta = array();

		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		Functions\when( 'get_term_meta' )->alias(
			function ( int $term_id, string $key, bool $single = false ) {
				unset( $term_id, $single );

				return $this->meta[ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El conjunto es cerrado y cada clave tiene su palabra. Si alguien agrega un
	 * estado sin etiqueta, el `<select>` del admin mostraría una opción en blanco
	 * y el renglón publicaría una cadena vacía.
	 *
	 * @testdox Should expose a closed set where every key has a Spanish label.
	 */
	public function test_the_state_set_is_closed_and_fully_labelled(): void {
		$states = PublicationStatus::states();

		self::assertSame(
			array(
				PublicationStatus::COMPLETE,
				PublicationStatus::ONGOING,
				PublicationStatus::PAUSED,
				PublicationStatus::CANCELLED,
			),
			array_keys( $states )
		);

		foreach ( $states as $key => $label ) {
			self::assertNotSame( '', trim( $label ), sprintf( 'El estado %s no tiene palabra publicable.', $key ) );
		}
	}

	/**
	 * @testdox Should keep work and edition state sets explicit and independently closed.
	 */
	public function test_work_and_edition_state_sets_are_independent(): void {
		self::assertSame( PublicationStatus::states(), PublicationStatus::work_states() );
		self::assertSame(
			array(
				PublicationStatus::COMPLETE,
				PublicationStatus::ONGOING,
				PublicationStatus::UP_TO_DATE,
				PublicationStatus::PAUSED,
				PublicationStatus::CANCELLED,
			),
			array_keys( PublicationStatus::edition_states() )
		);
		self::assertArrayNotHasKey( PublicationStatus::UP_TO_DATE, PublicationStatus::work_states() );
		self::assertSame( 'Finalizada', PublicationStatus::work_states()[ PublicationStatus::COMPLETE ] );
		self::assertSame( 'Completa', PublicationStatus::edition_states()[ PublicationStatus::COMPLETE ] );
	}

	/**
	 * Las cuatro claves viajan tal cual a `wp_termmeta` y hay lectores que las
	 * escriben en crudo (`SeriesSitemapLastmod`), así que su literal es el
	 * contrato — y las cuatro tienen que empezar por `_akb_`, que es el prefijo
	 * que borra el `uninstall.php`.
	 *
	 * Hasta 1.116.33 vivían además dos alias `@deprecated` (`META_STATE`,
	 * `META_TOTAL`) que ya sólo usaban estas pruebas; se retiraron por la regla
	 * del dueño «cero código muerto» y sus fixtures pasaron al nombre largo.
	 *
	 * @testdox Should pin the literal term-meta keys of the four scopes.
	 */
	public function test_meta_keys_are_the_literal_contract(): void {
		self::assertSame( '_akb_serie_estado', PublicationStatus::META_WORK_STATE );
		self::assertSame( '_akb_serie_tomos_obra', PublicationStatus::META_WORK_TOTAL );
		self::assertSame( '_akb_serie_estado_edicion', PublicationStatus::META_EDITION_STATE );
		self::assertSame( '_akb_serie_tomos_edicion', PublicationStatus::META_EDITION_TOTAL );
	}

	/**
	 * Las claves viajan a `wp_termmeta` y a los `value` del formulario: si alguna
	 * llevara acentos o mayúsculas, el `strtolower()` del saneo la rechazaría a
	 * sí misma en cuanto mbstring no estuviera.
	 *
	 * @testdox Should keep the stored keys ASCII and lowercase.
	 */
	public function test_the_stored_keys_are_ascii_and_lowercase(): void {
		foreach ( array_keys( PublicationStatus::states() ) as $key ) {
			self::assertMatchesRegularExpression( '/^[a-z_]+$/', $key );
		}
	}

	/**
	 * @testdox Should accept every key of the closed set.
	 */
	public function test_accepts_every_key_of_the_set(): void {
		foreach ( array_keys( PublicationStatus::states() ) as $key ) {
			self::assertSame( $key, PublicationStatus::sanitize_state( $key ) );
		}
	}

	/**
	 * Los 104 valores los va a cargar tanto el dueño desde el formulario como un
	 * script por WP-CLI. Un «Completa» con mayúscula no es un valor nuevo, es el
	 * mismo escrito distinto: rechazarlo dejaría muda una serie que sí tiene el
	 * dato.
	 *
	 * @testdox Should normalize case and stray whitespace before matching.
	 */
	public function test_normalizes_case_and_whitespace(): void {
		self::assertSame( PublicationStatus::COMPLETE, PublicationStatus::sanitize_state( ' Completa ' ) );
		self::assertSame( PublicationStatus::ONGOING, PublicationStatus::sanitize_state( 'EN_PUBLICACION' ) );
		self::assertSame( PublicationStatus::UP_TO_DATE, PublicationStatus::sanitize_edition_state( ' AL_DIA ' ) );
		self::assertSame( '', PublicationStatus::sanitize_work_state( PublicationStatus::UP_TO_DATE ) );
	}

	/**
	 * @testdox Should reject anything outside the closed set, including non-strings.
	 * @dataProvider provide_invalid_states
	 *
	 * @param mixed $raw Valor recibido.
	 */
	public function test_rejects_anything_outside_the_set( $raw ): void {
		self::assertSame( '', PublicationStatus::sanitize_state( $raw ) );
	}

	/** @return array<string, array<int, mixed>> */
	public static function provide_invalid_states(): array {
		return array(
			'cadena vacía'      => array( '' ),
			'sinónimo humano'   => array( 'terminada' ),
			'con acento'        => array( 'en_publicación' ),
			'valor con basura'  => array( 'completa; DROP TABLE' ),
			'array'             => array( array( 'completa' ) ),
			'null'              => array( null ),
			'entero'            => array( 1 ),
			'booleano'          => array( true ),
		);
	}

	/**
	 * @testdox Should accept a plain integer inside the plausible range.
	 */
	public function test_accepts_a_plain_integer_total(): void {
		self::assertSame( 23, PublicationStatus::sanitize_total( '23' ) );
		self::assertSame( 23, PublicationStatus::sanitize_total( ' 23 ' ) );
		self::assertSame( 23, PublicationStatus::sanitize_total( 23 ) );
		self::assertSame( 1, PublicationStatus::sanitize_total( '1' ) );
		self::assertSame( 999, PublicationStatus::sanitize_total( '999' ) );
	}

	/**
	 * `is_numeric('23.5')` es true y el cast lo convierte en 23 en silencio. Un
	 * total con decimales no es un total redondeado: es un dato que quien lo
	 * escribió no tenía.
	 *
	 * @testdox Should reject a total that is not a whole plausible number.
	 * @dataProvider provide_invalid_totals
	 *
	 * @param mixed $raw Valor recibido.
	 */
	public function test_rejects_an_implausible_total( $raw ): void {
		self::assertSame( 0, PublicationStatus::sanitize_total( $raw ) );
	}

	/** @return array<string, array<int, mixed>> */
	public static function provide_invalid_totals(): array {
		return array(
			'vacío'            => array( '' ),
			'cero'             => array( '0' ),
			'negativo'         => array( '-3' ),
			'decimal'          => array( '23.5' ),
			'notación exp'     => array( '2e3' ),
			'sobre el tope'    => array( '1000' ),
			'con texto'        => array( '23 tomos' ),
			'array'            => array( array( 23 ) ),
			'null'             => array( null ),
		);
	}

	/**
	 * El caso que motivó todo: Akibara tiene 17 tomos de una obra que terminó en
	 * 23, y el catálogo no puede distinguirlo de "la obra terminó en 17".
	 *
	 * @testdox Should publish a complete work whose total exceeds the catalogue.
	 */
	public function test_publishes_a_total_larger_than_the_catalogue(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => 'en 23 tomos',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 23, 17 )
		);
	}

	/**
	 * @testdox Should publish the total when it matches the catalogue exactly.
	 */
	public function test_publishes_a_total_equal_to_the_catalogue(): void {
		$phrase = PublicationStatus::phrase( PublicationStatus::COMPLETE, 17, 17 );

		self::assertSame( 'en 17 tomos', $phrase['detalle'] );
	}

	/**
	 * El catálogo cuenta productos de la edición vendida, no tomos de la obra
	 * original. Integrales, packs o divisiones editoriales hacen que esas dos
	 * cantidades no sean comparables.
	 *
	 * @testdox Should keep a work total even when the edition catalogue contains more products.
	 */
	public function test_keeps_a_work_total_when_the_edition_catalogue_is_larger(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => 'en 5 tomos',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 5, 17 )
		);
	}

	/**
	 * El argumento legacy acepta también cero sin alterar el total revisado.
	 *
	 * @testdox Should publish the total when the legacy catalogue count is zero.
	 */
	public function test_publishes_the_total_with_a_zero_legacy_catalogue_count(): void {
		$phrase = PublicationStatus::phrase( PublicationStatus::COMPLETE, 23, 0 );

		self::assertSame( 'en 23 tomos', $phrase['detalle'] );
	}

	/**
	 * @testdox Should keep the work headline scoped while formatting one tome in singular.
	 */
	public function test_names_a_single_volume_work_properly(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => 'en 1 tomo',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 1, 1 )
		);
	}

	/**
	 * Una obra de tomo único puede tener varios productos asociados —otra edición,
	 * un pack o una división editorial— sin dejar de ser una obra de un tomo.
	 *
	 * @testdox Should preserve a single-volume work over a larger edition catalogue.
	 */
	public function test_a_single_volume_work_survives_a_larger_edition_catalogue(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => 'en 1 tomo',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 1, 17 )
		);
	}

	/**
	 * El singular también es independiente del conteo legacy.
	 *
	 * @testdox Should publish a singular work total with a zero legacy catalogue count.
	 */
	public function test_a_single_volume_total_survives_a_zero_legacy_catalogue_count(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => 'en 1 tomo',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 1, 0 )
		);
	}

	/**
	 * Sin total, «Finalizada» sigue respondiendo la pregunta que trae el lector. Es
	 * el degradado normal, no un error.
	 *
	 * @testdox Should publish the bare headline when no total was written.
	 */
	public function test_publishes_the_bare_headline_without_a_total(): void {
		self::assertSame(
			array(
				'titular' => 'Finalizada',
				'detalle' => '',
			),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 0, 17 )
		);
	}

	/**
	 * En una obra en curso el total es desconocido por definición: publicar el
	 * que alguien tecleó sería inventar el dato con otro disfraz.
	 *
	 * @testdox Should never publish a total for a work that has not finished.
	 * @dataProvider provide_unfinished_states
	 *
	 * @param string $state    Clave del estado.
	 * @param string $expected Titular esperado.
	 */
	public function test_never_publishes_a_total_for_an_unfinished_work( string $state, string $expected ): void {
		$phrase = PublicationStatus::phrase( $state, 23, 17 );

		self::assertSame( $expected, $phrase['titular'] );
		self::assertSame( '', $phrase['detalle'] );
	}

	/** @return array<string, array<int, string>> */
	public static function provide_unfinished_states(): array {
		return array(
			'en publicación' => array( PublicationStatus::ONGOING, 'En publicación' ),
			'en pausa'       => array( PublicationStatus::PAUSED, 'En pausa' ),
			'cancelada'      => array( PublicationStatus::CANCELLED, 'Cancelada' ),
		);
	}

	/**
	 * Sin estado válido no hay renglón, y el total tampoco se cuela por su cuenta.
	 *
	 * @testdox Should return nothing at all when the state is outside the set.
	 */
	public function test_returns_nothing_for_a_state_outside_the_set(): void {
		self::assertSame( array(), PublicationStatus::phrase( 'terminada', 23, 17 ) );
		self::assertSame( array(), PublicationStatus::phrase( '', 23, 17 ) );
	}

	/**
	 * @testdox Should preserve phrase as the exact legacy wrapper of work phrase.
	 */
	public function test_phrase_is_the_legacy_work_phrase_wrapper(): void {
		self::assertSame(
			PublicationStatus::work_phrase( PublicationStatus::COMPLETE, 23, 17 ),
			PublicationStatus::phrase( PublicationStatus::COMPLETE, 23, 17 )
		);
	}

	/**
	 * @testdox Should compose edition totals according to edition state.
	 * @dataProvider provide_edition_phrases
	 *
	 * @param string                            $state    Edition state.
	 * @param int                               $total    Announced edition total.
	 * @param array{titular:string,detalle:string} $expected Expected phrase.
	 */
	public function test_composes_edition_phrases( string $state, int $total, array $expected ): void {
		self::assertSame( $expected, PublicationStatus::edition_phrase( $state, $total ) );
	}

	/** @return array<string, array{string,int,array{titular:string,detalle:string}}> */
	public static function provide_edition_phrases(): array {
		return array(
			'completa con total'      => array( PublicationStatus::COMPLETE, 8, array( 'titular' => 'Completa', 'detalle' => 'en 8 tomos' ) ),
			'completa singular'       => array( PublicationStatus::COMPLETE, 1, array( 'titular' => 'Completa', 'detalle' => 'en 1 tomo' ) ),
			'en curso con previsto'   => array( PublicationStatus::ONGOING, 8, array( 'titular' => 'En publicación', 'detalle' => '8 tomos previstos' ) ),
			'en curso singular'       => array( PublicationStatus::ONGOING, 1, array( 'titular' => 'En publicación', 'detalle' => '1 tomo previsto' ) ),
			'al día ignora total'     => array( PublicationStatus::UP_TO_DATE, 8, array( 'titular' => 'Al día', 'detalle' => '' ) ),
			'en pausa ignora total'   => array( PublicationStatus::PAUSED, 8, array( 'titular' => 'En pausa', 'detalle' => '' ) ),
			'cancelada ignora total'  => array( PublicationStatus::CANCELLED, 8, array( 'titular' => 'Cancelada', 'detalle' => '' ) ),
			'en curso sin total'      => array( PublicationStatus::ONGOING, 0, array( 'titular' => 'En publicación', 'detalle' => '' ) ),
		);
	}

	/**
	 * @testdox Should reject a work-only or unknown state from the edition phrase.
	 */
	public function test_edition_phrase_rejects_unknown_states(): void {
		self::assertSame( array(), PublicationStatus::edition_phrase( '', 8 ) );
		self::assertSame( array(), PublicationStatus::edition_phrase( 'terminada', 8 ) );
	}

	/**
	 * @testdox Should read both metas of a term, already normalized.
	 */
	public function test_reads_both_metas_of_a_term(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => ' COMPLETA ',
			PublicationStatus::META_WORK_TOTAL => '23',
		);

		self::assertSame(
			array(
				'estado'     => PublicationStatus::COMPLETE,
				'tomos_obra' => 23,
			),
			PublicationStatus::for_term( 900 )
		);
	}

	/**
	 * @testdox Should read and sanitize work and edition metas independently.
	 */
	public function test_reads_both_publication_scopes(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE    => ' COMPLETA ',
			PublicationStatus::META_WORK_TOTAL    => '193',
			PublicationStatus::META_EDITION_STATE => ' AL_DIA ',
			PublicationStatus::META_EDITION_TOTAL => '8',
		);

		self::assertSame(
			array(
				'obra'    => array(
					'estado' => PublicationStatus::COMPLETE,
					'tomos'  => 193,
				),
				'edicion' => array(
					'estado' => PublicationStatus::UP_TO_DATE,
					'tomos'  => 8,
				),
			),
			PublicationStatus::scoped_for_term( 900 )
		);
	}

	/**
	 * El formulario no es la única puerta a `wp_termmeta`: un SQL o un WP-CLI con
	 * un typo escriben lo que sea, y el renglón tiene que callarse en vez de
	 * publicarlo.
	 *
	 * @testdox Should sanitize on read, not only on write.
	 */
	public function test_sanitizes_what_the_database_already_holds(): void {
		$this->meta = array(
			PublicationStatus::META_WORK_STATE => 'finalizada',
			PublicationStatus::META_WORK_TOTAL => '99999',
		);

		self::assertSame(
			array(
				'estado'     => '',
				'tomos_obra' => 0,
			),
			PublicationStatus::for_term( 900 )
		);
	}

	/**
	 * @testdox Should not touch the database for an impossible term id.
	 */
	public function test_does_not_read_for_an_impossible_term(): void {
		$reads = 0;
		Functions\when( 'get_term_meta' )->alias(
			static function () use ( &$reads ): string {
				++$reads;
				return '';
			}
		);

		self::assertSame(
			array(
				'estado'     => '',
				'tomos_obra' => 0,
			),
			PublicationStatus::for_term( 0 )
		);
		self::assertSame( 0, $reads );
	}
}
