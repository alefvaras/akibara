<?php
/**
 * Guardar una pestaña del panel no puede CREAR filas que nadie tocó.
 *
 * `woocommerce_update_options()` escribe todos los campos de la pestaña, se
 * hayan tocado o no. Para casi todos es inocuo; deja de serlo en cuanto la
 * option entra en la lista que vigila akibara-search, porque crear la fila mueve
 * la huella de la fuente editorial. El 2026-09-05 el dueño puso la promo desde
 * el panel y ese mismo guardado dejó la tienda 30 minutos sin buscador ni
 * facetas.
 *
 * El barrido del panel completo lo midió después: guardar en blanco creaba 47
 * filas sólo en «Textos». Por eso la guarda vive en el panel y no campo a campo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminPanel;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class UntouchedDefaultsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $filas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filas = array();

		Functions\when( 'get_option' )->alias(
			fn( string $nombre, $default = false ) => array_key_exists( $nombre, $this->filas )
				? $this->filas[ $nombre ]
				: $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $defaults option => default declarado.
	 */
	private function panel_con( array $defaults ): AdminPanel {
		$panel = new AdminPanel();
		$prop  = new ReflectionProperty( AdminPanel::class, 'untouched_defaults' );
		$prop->setAccessible( true );
		$prop->setValue( $panel, $defaults );

		return $panel;
	}

	/**
	 * El caso exacto que se cobró los 30 minutos: la fila no existe y el
	 * formulario trae el valor que se pintó.
	 *
	 * @testdox Con la fila ausente y el default sin tocar, no se escribe nada.
	 */
	public function test_an_untouched_default_is_not_written(): void {
		$panel = $this->panel_con( array( 'akb_discount_max_pct' => '15' ) );

		self::assertFalse(
			$panel->skip_untouched_default( '15', 'akb_discount_max_pct', false ),
			'Devolver el valor viejo es lo que hace que update_option() salga sin escribir.'
		);
	}

	/**
	 * @testdox Un cambio real se guarda siempre, exista o no la fila.
	 */
	public function test_a_real_change_is_always_saved(): void {
		$panel = $this->panel_con( array( 'akb_discount_max_pct' => '15' ) );

		self::assertSame( '20', $panel->skip_untouched_default( '20', 'akb_discount_max_pct', false ) );

		$this->filas['akb_discount_max_pct'] = '20';
		self::assertSame(
			'15',
			$panel->skip_untouched_default( '15', 'akb_discount_max_pct', '20' ),
			'Con la fila ya creada, volver al valor por defecto es una decisión del operador.'
		);
	}

	/**
	 * @testdox Una option que no es de esta pestaña no se toca.
	 */
	public function test_options_outside_the_tab_are_left_alone(): void {
		$panel = $this->panel_con( array( 'akb_discount_max_pct' => '15' ) );

		self::assertSame( 'yes', $panel->skip_untouched_default( 'yes', 'otra_option', false ) );
	}

	/**
	 * El mapa se saca de la propia definición de la pestaña, así que un campo
	 * que no declara `default` no puede compararse y se guarda como siempre.
	 *
	 * @testdox El mapa ignora títulos, cierres de sección y campos sin default.
	 */
	public function test_the_map_only_takes_fields_with_a_declared_default(): void {
		$metodo = new ReflectionMethod( AdminPanel::class, 'defaults_by_option' );
		$metodo->setAccessible( true );

		$mapa = $metodo->invoke(
			null,
			array(
				array(
					'type' => 'title',
					'id'   => 'akb_seccion',
				),
				array(
					'id'      => 'akb_con_default',
					'type'    => 'text',
					'default' => 'hola',
				),
				array(
					'id'   => 'akb_sin_default',
					'type' => 'text',
				),
				array(
					'id'      => 'akb_default_array',
					'type'    => 'multiselect',
					'default' => array( 'a', 'b' ),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'akb_seccion',
				),
			)
		);

		self::assertSame( array( 'akb_con_default' => 'hola' ), $mapa );
	}

	/**
	 * Los campos numéricos llegan como int tras el saneado y su default se
	 * declara como cadena: compararlos sin normalizar dejaría la guarda muerta
	 * justo en el campo que provocó el incidente.
	 *
	 * @testdox Un entero y su default en texto se reconocen como el mismo valor.
	 */
	public function test_an_int_matches_its_string_default(): void {
		$panel = $this->panel_con( array( 'akb_discount_max_pct' => '15' ) );

		self::assertFalse( $panel->skip_untouched_default( 15, 'akb_discount_max_pct', false ) );
	}

	/**
	 * El panel decía «Ajustes guardados correctamente» aunque la escritura se
	 * hubiera rechazado debajo: medido en producción el 2026-09-05, WP-CLI
	 * responde «Error: Could not update option» y el panel felicita. Por eso el
	 * dueño necesitaba guardar dos veces sin saber por qué.
	 *
	 * @testdox Un campo que se intentó escribir y no quedó sale en el aviso, con su nombre.
	 */
	public function test_a_write_that_did_not_stick_is_reported(): void {
		$panel = $this->panel_con( array() );

		// El operador cambia dos fechas; el candado del buscador rechaza la segunda.
		$panel->skip_untouched_default( '2026-09-05', 'akibara_taxonomy_discount_from', '2026-09-04' );
		$panel->skip_untouched_default( '2026-09-06', 'akibara_taxonomy_discount_to', '2026-09-04' );
		$this->filas['akibara_taxonomy_discount_from'] = '2026-09-05';
		$this->filas['akibara_taxonomy_discount_to']   = '2026-09-04'; // no entró

		self::assertSame(
			array( 'akibara_taxonomy_discount_to' ),
			$this->fallidos( $panel ),
			'El que quedó con el valor viejo tiene que decirse por su nombre; el que sí entró, no.'
		);
	}

	/**
	 * La trampa de juntar las dos piezas: sin esto, los 47 defaults intactos de
	 * «Textos» saldrían como fallos y el panel pasaría de mentir en una
	 * dirección a mentir en la otra.
	 *
	 * @testdox Un default intacto que la guarda saltó a propósito NO sale como fallo.
	 */
	public function test_a_deliberately_skipped_default_is_not_reported(): void {
		$panel = $this->panel_con( array( 'akb_copy_eta_tba' => 'Te avisamos en cuanto tengamos fecha' ) );

		// La fila no existe y llega el default: la guarda lo salta a propósito.
		$saltado = $panel->skip_untouched_default( 'Te avisamos en cuanto tengamos fecha', 'akb_copy_eta_tba', false );

		self::assertFalse( $saltado, 'Precondición: la guarda lo saltó.' );
		self::assertSame(
			array(),
			$this->fallidos( $panel ),
			'No se intentó escribir, así que no puede figurar como que falló.'
		);
	}

	/**
	 * @testdox Lo que se compara es lo que llegó a escribirse, no lo que se tecleó.
	 */
	public function test_the_comparison_uses_the_sanitised_value(): void {
		$panel = $this->panel_con( array() );

		// WooCommerce sanea antes del filtro: aquí llega ya normalizado.
		$panel->skip_untouched_default( '15', 'akb_discount_max_pct', false );
		$this->filas['akb_discount_max_pct'] = 15; // la base devuelve int

		self::assertSame(
			array(),
			$this->fallidos( $panel ),
			'«15» y 15 son el mismo valor guardado: acusarlo de fallo sería un falso positivo.'
		);
	}

	/**
	 * La cadena real que se cobró un aviso falso en producción: el filtro
	 * específico de `TaxonomyDiscount` corría ANTES y devolvía el valor viejo
	 * —`false` con la fila ausente— para no crear la fila. La guarda genérica
	 * recibía ya ese `false`, no lo reconocía como «el default intacto» y lo
	 * apuntaba como intento. Después el aviso lo acusaba de no haberse guardado.
	 *
	 * La regla que faltaba es de Core, no de este panel: si el valor entrante es
	 * idéntico al viejo, `update_option()` sale sin escribir. Eso nunca es un
	 * fallo, lo haya decidido una guarda mía o un filtro de un tercero.
	 *
	 * @testdox Un valor que otro filtro dejó igual al viejo no se acusa de fallo.
	 */
	public function test_a_value_another_filter_left_untouched_is_not_reported(): void {
		$panel = $this->panel_con( array( 'akb_discount_max_pct' => '15' ) );

		// Lo que llega ya NO es '15': un filtro anterior lo dejó en el valor
		// viejo, que con la fila ausente es `false`.
		$devuelto = $panel->skip_untouched_default( false, 'akb_discount_max_pct', false );

		self::assertFalse( $devuelto, 'Precondición: sigue sin escribirse.' );
		self::assertSame(
			array(),
			$this->fallidos( $panel ),
			'La fila no existe porque nadie la quiso crear. Acusarla empuja al dueño a guardar otra vez.'
		);
	}

	/**
	 * @testdox Lo mismo con una option cualquiera: valor igual al viejo, sin acusación.
	 */
	public function test_an_unchanged_value_is_never_reported(): void {
		$panel = $this->panel_con( array() );

		$panel->skip_untouched_default( 'no', 'akb_alguna_option', 'no' );
		$this->filas['akb_alguna_option'] = 'no';

		self::assertSame( array(), $this->fallidos( $panel ) );
	}

	/**
	 * @param AdminPanel $panel Panel con intentos ya registrados.
	 * @return array<int, string>
	 */
	private function fallidos( AdminPanel $panel ): array {
		$metodo = new ReflectionMethod( AdminPanel::class, 'options_that_did_not_stick' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( $panel );
	}

	/**
	 * @testdox Un valor no escalar se guarda sin comparar.
	 */
	public function test_a_non_scalar_value_is_saved_untouched(): void {
		$panel = $this->panel_con( array( 'akb_lista' => 'a' ) );
		$valor = array( 'a', 'b' );

		self::assertSame( $valor, $panel->skip_untouched_default( $valor, 'akb_lista', false ) );
	}
}
