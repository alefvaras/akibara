<?php
/**
 * Lo que el panel guarda como sinónimo, el motor tiene que aceptarlo al leer.
 *
 * Defecto encontrado el 2026-09-13 al documentar el buscador para el dueño: el
 * guardado contaba sólo las palabras SIGNIFICATIVAS de un término (sin «el»,
 * «de», «los») y el lector contaba TODAS. «el ataque de los titanes» son cinco
 * palabras y dos significativas: el panel lo guardaba como válido y el lector
 * lo rechazaba. Un solo término rechazado invalida la configuración entera del
 * buscador, y con ella se apagan a la vez la indexación, las sugerencias y los
 * filtros. En producción no había sinónimos guardados, así que la trampa estaba
 * armada para el primer título largo que alguien escribiera.
 *
 * El contrato que fija esta prueba es de ida y vuelta, no de casos sueltos:
 * para CUALQUIER entrada, lo que sale del guardado pasa el lector.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests {

	use Akibara\Search\Synonyms;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/src/Normalizer.php';
	require_once dirname( __DIR__ ) . '/src/Stopwords.php';
	require_once dirname( __DIR__ ) . '/src/Synonyms.php';

	final class SinonimoGuardadoEsLeidoTest extends TestCase {

		/**
		 * @testdox Lo que el panel guarda, el lector lo acepta ($caso).
		 * @dataProvider entradas
		 *
		 * @param array<int, mixed> $grupos Lo que el dueño escribió en el panel.
		 */
		public function test_lo_guardado_pasa_el_lector( array $grupos ): void {
			$guardado = Synonyms::sanitize_groups( $grupos );

			$this->assertTrue(
				Synonyms::configuration_is_canonical( $guardado ),
				'Un término guardado que el lector rechaza apaga indexación, sugerencias y filtros a la vez: ' . wp_json_encode_prueba( $guardado )
			);
		}

		/** @return array<string, array{0: array<int, mixed>}> */
		public static function entradas(): array {
			return array(
				'título largo con palabras de relleno' => array( array( array( 'aot', 'el ataque de los titanes' ) ) ),
				'cuatro significativas y dos vacías'   => array( array( array( 'guardianes de la noche del sol', 'demon slayer' ) ) ),
				'sólo signos'                           => array( array( array( '¡¿!', 'naruto', 'uzumaki' ) ) ),
				'sólo palabras de relleno'              => array( array( array( 'de la', 'one piece', 'op' ) ) ),
				'un menor que suelto'                   => array( array( array( 'a < b', 'algo', 'otro' ) ) ),
				// El `sanitize_text_field` real deja pasar un «>» suelto: es el
				// único de los dos signos que llega vivo al guardado de verdad.
				'un mayor que suelto'                   => array( array( array( 'tomo > 3', 'volumen', 'vol' ) ) ),
				'mayúsculas duplicadas'                 => array( array( array( 'JJK', 'jjk', 'jujutsu kaisen' ) ) ),
				'espacios alrededor'                    => array( array( array( '  hunter x hunter  ', 'hxh' ) ) ),
				'grupo que queda con un solo término'   => array( array( array( 'el ataque de los titanes', 'aot' ), array( 'jjk', 'jujutsu kaisen' ) ) ),
				'los valores de fábrica'                => array( Synonyms::defaults() ),
			);
		}

		/** @testdox Un término que el lector no aceptaría no se guarda, y el panel lo avisa. */
		public function test_el_termino_invalido_se_descarta_y_se_avisa(): void {
			$truncado = false;
			$guardado = Synonyms::sanitize_groups( array( array( 'aot', 'attack on titan', 'el ataque de los titanes' ) ), $truncado );

			$this->assertSame( array( array( 'aot', 'attack on titan' ) ), $guardado );
			$this->assertTrue( $truncado, 'El panel tiene que decir que se descartó algo.' );
		}

		/** @testdox Cuatro palabras, aunque sean de relleno, siguen entrando. */
		public function test_cuatro_palabras_entran(): void {
			$guardado = Synonyms::sanitize_groups( array( array( 'ataque a los titanes', 'aot' ) ) );

			$this->assertSame( array( array( 'ataque a los titanes', 'aot' ) ), $guardado );
		}
	}

	/**
	 * JSON legible para el mensaje de la aserción.
	 *
	 * @param mixed $dato Dato.
	 */
	function wp_json_encode_prueba( $dato ): string {
		return (string) json_encode( $dato, JSON_UNESCAPED_UNICODE );
	}
}
