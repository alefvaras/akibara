<?php
/**
 * Lo que el panel dice después de guardar tiene que ser una sola verdad.
 *
 * El 2026-09-05 llegó a decir las dos cosas a la vez: un aviso rojo con la lista
 * de campos que no entraron, y justo debajo uno amarillo afirmando que no se
 * había guardado NINGÚN ajuste de la pestaña. Los dos no pueden ser ciertos, y
 * el segundo además mandaba «corrige los errores de arriba» cuando no había
 * nada que corregir: quien rechazó la escritura fue el buscador.
 *
 * La causa era de cableado: el aviso parcial viajaba por el mismo transient que
 * los errores de validación previa, y ese canal significa «no se escribió
 * nada». Son dos situaciones distintas y necesitan dos canales.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminPanel;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PostSaveNoticesTest extends TestCase {

	/**
	 * @param array<int, string>    $errores   Validación previa.
	 * @param array<string, string> $parciales option => etiqueta (o `['etiqueta'=>…, 'motivo'=>…]`) del campo que no quedó escrito.
	 * @return array<int, array<string, string>>
	 */
	private function avisos( bool $saved, array $errores, array $parciales, bool $purged = true, bool $cambios = true, string $motivo_sin_purga = '' ): array {
		$metodo = new ReflectionMethod( AdminPanel::class, 'post_save_notices' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( null, $saved, $errores, $parciales, $purged, $cambios, $motivo_sin_purga );
	}

	/** @param array<int, array<string, string>> $avisos */
	private function tipos( array $avisos ): array {
		return array_map( static fn( array $aviso ): string => $aviso['tipo'], $avisos );
	}

	/** @param array<int, array<string, string>> $avisos */
	private function todo_el_texto( array $avisos ): string {
		return implode( ' | ', array_map( static fn( array $aviso ): string => $aviso['texto'], $avisos ) );
	}

	/**
	 * @testdox Guardado limpio: un solo aviso, el verde.
	 */
	public function test_a_clean_save_says_only_that(): void {
		$avisos = $this->avisos( true, array(), array() );

		self::assertSame( array( 'success' ), $this->tipos( $avisos ) );
		self::assertStringContainsString( 'guardados correctamente', $this->todo_el_texto( $avisos ) );
	}

	/**
	 * @testdox Sin guardado no se pinta ningún aviso.
	 */
	public function test_a_plain_visit_says_nothing(): void {
		self::assertSame( array(), $this->avisos( false, array(), array() ) );
	}

	/**
	 * `handle_save()` redirige ANTES de escribir nada en cuanto la validación
	 * devuelve algo, así que aquí «ningún ajuste» es literalmente cierto.
	 *
	 * @testdox Con errores de validación sí se dice que no se guardó nada.
	 */
	public function test_validation_errors_do_say_nothing_was_saved(): void {
		$avisos = $this->avisos( true, array( 'La fecha inicial es posterior a la final.' ), array() );

		self::assertSame( array( 'error', 'warning' ), $this->tipos( $avisos ) );
		self::assertStringContainsString( 'No se guardó ningún ajuste', $this->todo_el_texto( $avisos ) );
	}

	/**
	 * El caso que se contradecía. Entró casi todo; decir «ningún ajuste» es
	 * falso y hace desconfiar de lo que sí quedó escrito.
	 *
	 * @testdox Un rechazo parcial nombra los campos y NO dice que no se guardó nada.
	 */
	public function test_a_partial_rejection_never_claims_nothing_was_saved(): void {
		$avisos = $this->avisos( true, array(), array( 'akibara_taxonomy_discount_to' => 'Vigencia hasta (opcional)' ) );
		$texto  = $this->todo_el_texto( $avisos );

		self::assertSame( array( 'warning' ), $this->tipos( $avisos ), 'Ni el verde de éxito ni el de «no se guardó nada».' );
		self::assertStringContainsString( 'Vigencia hasta', $texto, 'El campo se nombra como lo ve el dueño, no como se llama la fila.' );
		self::assertStringNotContainsString( 'ningún ajuste', $texto );
		self::assertStringNotContainsString( 'guardados correctamente', $texto, 'Felicitar con un campo fuera es la mentira original.' );
	}

	/**
	 * La explicación que llevaba —«el buscador rechaza más de un cambio de
	 * precios por envío»— se midió en producción y era falsa: entraron las dos
	 * fechas del mismo envío. Lo que rechaza es que la publicación tenga deuda
	 * sin saldar.
	 *
	 * @testdox El aviso parcial no repite la causa que la medición descartó.
	 */
	public function test_the_partial_notice_does_not_repeat_the_refuted_cause(): void {
		$texto = $this->todo_el_texto( $this->avisos( true, array(), array( 'akb_una_option' => 'Un campo' ) ) );

		self::assertStringNotContainsString( 'más de un cambio de precios por envío', $texto );
	}

	/**
	 * @testdox Con errores de validación, un rechazo parcial no añade ruido encima.
	 */
	public function test_validation_errors_win_over_a_partial_rejection(): void {
		$avisos = $this->avisos( true, array( 'Algo no cuadra.' ), array( 'akb_una_option' => 'Un campo' ) );

		self::assertSame( array( 'error', 'warning' ), $this->tipos( $avisos ) );
		self::assertStringNotContainsString( 'Un campo', $this->todo_el_texto( $avisos ) );
	}

	/**
	 * Un campo sin etiqueta declarada no puede quedarse mudo: vale más el nombre
	 * de la fila que un aviso que no dice cuál.
	 *
	 * @testdox Sin etiqueta se cae al nombre técnico, no al silencio.
	 */
	public function test_a_field_without_a_label_falls_back_to_its_option_name(): void {
		$texto = $this->todo_el_texto( $this->avisos( true, array(), array( 'akb_sin_etiqueta' => '' ) ) );

		self::assertStringContainsString( 'akb_sin_etiqueta', $texto );
	}

	/**
	 * Desde 1.108.9 las pestañas sin superficie cacheada no purgan el sitio
	 * entero, y prometerlo ahí sería mentira.
	 *
	 * @testdox El aviso verde sólo promete limpiar la caché cuando la limpió.
	 */
	public function test_the_success_notice_only_promises_a_purge_when_it_purged(): void {
		self::assertStringContainsString( 'Se limpió la caché', $this->todo_el_texto( $this->avisos( true, array(), array(), true ) ) );
		self::assertStringNotContainsString( 'Se limpió la caché', $this->todo_el_texto( $this->avisos( true, array(), array(), false ) ) );
	}

	/**
	 * Hallazgo S-45c17f64: el verde se decidía sólo por la pestaña. Guardar sin
	 * cambiar nada en una pestaña con superficie cacheada no purga, y el aviso
	 * decía «se limpió la caché».
	 *
	 * @testdox Guardar sin cambios no promete purga ni habla de carrito: dice que no había cambios.
	 */
	public function test_a_save_without_changes_says_so(): void {
		$texto = $this->todo_el_texto( $this->avisos( true, array(), array(), false, false ) );

		self::assertStringNotContainsString( 'Se limpió la caché', $texto );
		self::assertStringNotContainsString( 'carrito y checkout', $texto );
		self::assertStringContainsString( 'No había cambios', $texto );
	}

	/**
	 * REAPERTURA S-45c17f64 (2026-09-19): el verde afirmaba SIEMPRE "carrito y
	 * checkout" para cualquier pestaña sin superficie cacheada, aunque fuera
	 * falso — `imagenes` y `velocidad` no tienen nada que ver con ninguno de
	 * los dos. Cuando `render_page()` pasa el motivo real de la pestaña, el
	 * verde lo cita en vez de la frase fija.
	 *
	 * @testdox El verde sin purga cita el motivo real de la pestaña, no "carrito y checkout" a ciegas.
	 */
	public function test_the_success_notice_uses_the_tab_specific_reason(): void {
		$texto = $this->todo_el_texto(
			$this->avisos(
				true,
				array(),
				array(),
				false,
				true,
				'Esta calidad se aplica a las imágenes que subas de ahora en adelante; las ya subidas no cambian hasta que uses «Recomprimir».'
			)
		);

		self::assertStringContainsString( 'Recomprimir', $texto );
		self::assertStringNotContainsString( 'carrito y checkout', $texto );
	}

	/**
	 * Sin motivo declarado (una exención nueva que todavía no lo trae), el
	 * respaldo tiene que ser neutro: no puede inventar "carrito y checkout"
	 * como si fuera universal.
	 *
	 * @testdox Sin motivo declarado, el respaldo no nombra carrito ni checkout.
	 */
	public function test_a_partial_without_reason_does_not_blame_the_search_index(): void {
		$texto = $this->todo_el_texto( $this->avisos( true, array(), array(), false, true, '' ) );

		self::assertStringNotContainsString( 'carrito y checkout', $texto );
		self::assertStringContainsString( 'guardados correctamente', $texto );
	}

	/**
	 * REAPERTURA S-74d49f79 (2026-09-19): el rechazo parcial repetía SIEMPRE
	 * la causa del buscador («está poniéndose al día con el catálogo… espera
	 * a que termine»), aunque el campo rechazado no tuviera nada que ver con
	 * el índice — un correo mal escrito, por ejemplo. El dueño leía que debía
	 * ESPERAR cuando lo que hacía falta era CORREGIR el dato.
	 *
	 * @testdox Un rechazo parcial imprime el motivo que se le dio, no el del buscador a ciegas.
	 */
	public function test_a_partial_rejection_prints_the_reason_it_was_given(): void {
		$texto = $this->todo_el_texto(
			$this->avisos(
				true,
				array(),
				array(
					'akb_forms_notify_email' => array(
						'etiqueta' => 'Correo que recibe los mensajes',
						'motivo'   => 'Ese correo no es válido, así que se dejó el de siempre. Corrígelo y guarda de nuevo.',
					),
				)
			)
		);

		self::assertStringContainsString( 'Correo que recibe los mensajes', $texto );
		self::assertStringContainsString( 'Corrígelo y guarda de nuevo', $texto );
		self::assertStringNotContainsString( 'buscador', $texto );
		self::assertStringNotContainsString( 'Espera a que termine', $texto );
	}

	/**
	 * Sin motivo explícito (la forma vieja del array, sólo etiqueta), el
	 * aviso no puede inventar una causa: se queda con el nombre del campo y
	 * nada más, en vez de acusar al buscador por defecto.
	 *
	 * @testdox Un rechazo parcial sin motivo no acusa al buscador por defecto.
	 */
	public function test_a_partial_without_a_reason_does_not_blame_the_search_module(): void {
		$texto = $this->todo_el_texto( $this->avisos( true, array(), array( 'akb_algo' => 'Un campo' ) ) );

		self::assertStringNotContainsString( 'buscador', $texto );
		self::assertStringNotContainsString( 'poniéndose al día', $texto );
	}

	/**
	 * Dos campos con motivos DISTINTOS no pueden fundirse en una sola frase
	 * que sólo cite uno de los dos: cada motivo se pinta.
	 *
	 * @testdox Dos motivos distintos se pintan los dos, no sólo el primero.
	 */
	public function test_two_different_reasons_both_get_printed(): void {
		$texto = $this->todo_el_texto(
			$this->avisos(
				true,
				array(),
				array(
					'akb_correo' => array(
						'etiqueta' => 'Correo',
						'motivo'   => 'Motivo del correo.',
					),
					'akb_guia'   => array(
						'etiqueta' => 'Guía',
						'motivo'   => 'Motivo de la guía.',
					),
				)
			)
		);

		self::assertStringContainsString( 'Motivo del correo.', $texto );
		self::assertStringContainsString( 'Motivo de la guía.', $texto );
	}

	/**
	 * R-I1-5 (revisión del lote I1): un campo que SÍ se guardó pero tiene algo
	 * que revisar (un botón de guía sin {serie}, un texto recortado) viajaba
	 * como rechazo, y el panel decía «Se guardó todo menos esto: Guía: botón a
	 * la serie. Se guardó, pero sin {serie}…». Ahora va aparte, junto al verde.
	 *
	 * @testdox Un campo guardado con aviso no se lista como «todo menos esto» y el verde se mantiene.
	 */
	public function test_a_saved_field_with_a_warning_is_not_a_rejection(): void {
		$avisos = $this->avisos(
			true,
			array(),
			array(
				'akb_copy_guide_cta_series' => array(
					'etiqueta' => 'Guía: botón a la serie',
					'motivo'   => 'Sin {serie} el botón no nombra la serie.',
					'guardado' => true,
				),
			)
		);
		$texto  = $this->todo_el_texto( $avisos );

		self::assertSame( array( 'success', 'warning' ), $this->tipos( $avisos ) );
		self::assertStringContainsString( 'guardados correctamente', $texto );
		self::assertStringContainsString( 'Revisa este campo: Guía: botón a la serie. Sin {serie} el botón no nombra la serie.', $texto );
		self::assertStringNotContainsString( 'menos esto', $texto, 'Ese campo sí se guardó.' );
	}

	/**
	 * Guardar otra vez sin tocar nada con el botón aún sin {serie}: el aviso
	 * sigue (el campo sigue sin nombrar la serie) y no puede decir «se guardó»
	 * al lado de «No había cambios».
	 *
	 * @testdox Con «No había cambios», el aviso del campo no afirma que se guardó.
	 */
	public function test_a_warning_next_to_no_changes_does_not_claim_a_save(): void {
		$avisos = $this->avisos(
			true,
			array(),
			array(
				'akb_x' => array(
					'etiqueta' => 'Un texto',
					'motivo'   => 'Quedó recortado: no cabía entero.',
					'guardado' => true,
				),
			),
			false,
			false
		);

		self::assertSame( array( 'success', 'warning' ), $this->tipos( $avisos ) );
		self::assertStringContainsString( 'No había cambios', $avisos[0]['texto'] );
		self::assertStringStartsWith( 'Revisa este campo: Un texto.', $avisos[1]['texto'] );
	}

	/**
	 * @testdox Rechazo y aviso a la vez: cada campo en su frase, sin verde.
	 */
	public function test_a_rejection_and_a_warning_each_get_their_own_notice(): void {
		$avisos = $this->avisos(
			true,
			array(),
			array(
				'akb_correo' => array(
					'etiqueta' => 'Correo',
					'motivo'   => 'Ese correo no es válido.',
				),
				'akb_a'      => array(
					'etiqueta' => 'Texto A',
					'motivo'   => 'Quedó recortado: no cabía entero.',
					'guardado' => true,
				),
				'akb_b'      => array(
					'etiqueta' => 'Texto B',
					'motivo'   => 'Quedó recortado: no cabía entero.',
					'guardado' => true,
				),
			)
		);

		self::assertSame( array( 'warning', 'warning' ), $this->tipos( $avisos ), 'Con un campo fuera no hay verde.' );
		self::assertSame( 'Se guardó todo menos esto: Correo. Ese correo no es válido.', $avisos[0]['texto'] );
		self::assertSame( 'Revisa estos campos: Texto A, Texto B. Quedó recortado: no cabía entero.', $avisos[1]['texto'] );
	}

	/**
	 * `handle_save()` redirige y sale, así que se fija el cableado en la fuente:
	 * el canal nuevo existe, marca `guardado` y no pisa un rechazo del mismo campo.
	 *
	 * @testdox El guardado pide los avisos de lo que sí se guardó por su propio filtro.
	 */
	public function test_handle_save_collects_saved_with_warning(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/AdminPanel/AdminPanel.php' );

		self::assertStringContainsString( "apply_filters( 'akibara_panel_options_saved_with_warning', array(), \$tab )", $fuente );
		self::assertMatchesRegularExpression( "/'guardado' => true,/", $fuente );
		self::assertStringContainsString( 'isset( $parciales[ $option ] )', $fuente, 'Un campo rechazado se queda con el aviso del rechazo.' );
	}
}
