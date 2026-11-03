<?php
/**
 * INVARIANTE transversal: el texto del dueño NUNCA es el formato de un sprintf.
 *
 * Los copys del panel los escribe el dueño, no un programador. El día que
 * escriba «20% de descuento» en un texto que alguien pasó como primer argumento
 * de `sprintf()`, PHP intentará interpretar ese `%` como un marcador y el
 * resultado es un error fatal. Pasó: un «%» en un texto editable tumbó
 * /mi-cuenta y los correos.
 *
 * El arreglo de entonces fue puntual. Este candado es el general: **el formato
 * de `sprintf`/`printf` tiene que ser un literal escrito por nosotros**. Si hace
 * falta meter el texto del dueño, va como ARGUMENTO (`sprintf( '%s en Akibara',
 * $texto_del_dueño )`) o con `str_replace` sobre un marcador.
 *
 * Se comprueba sobre el árbol entero, no sobre las clases que ya lo tuvieron,
 * porque el defecto se propaga por copia: cerrar los sitios conocidos y dejar el
 * patrón vivo garantiza que el siguiente nazca igual.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class CopyNeverUsedAsFormatTest extends TestCase {

	/**
	 * Un formato puede ser un literal, o un ternario que elige ENTRE literales
	 * (el caso singular/plural, que es legítimo y está vivo en AdminDashboard).
	 * Cualquier otra cosa es una variable, y una variable puede traer un `%`.
	 */
	private function formato_es_seguro( string $argumento ): bool {
		$argumento = ltrim( $argumento );

		if ( '' === $argumento ) {
			return false;
		}

		// Literal directo: la forma normal y la mayoritaria.
		if ( "'" === $argumento[0] || '"' === $argumento[0] ) {
			return true;
		}

		// Ternario entre literales, con paréntesis o sin ellos y partido en varias
		// líneas: `( $cond ? 'uno' : 'otro' )` y también
		// `sprintf(\n 1 === $n ? '%s pedido' : '%s pedidos',`, que es como lo
		// escribe el panel de Mi cuenta.
		//
		// La guarda del `$` no es cosmética: sin ella, `sprintf( $copy . ( $x ?
		// 'a' : 'b' ), … )` casaría el patrón y daría por bueno un formato que
		// empieza por una variable, que es exactamente el defecto.
		if ( '$' !== $argumento[0] && 1 === preg_match( "/\?\s*['\"].*:\s*['\"]/s", $argumento ) ) {
			return true;
		}

		// Escapado alrededor del literal: `printf( esc_html( 'Hola %s,' ), … )`.
		// Es la forma que usan las plantillas de correo, y es SEGURA porque lo
		// que se escapa es una cadena nuestra. Se mira lo que hay DENTRO, no la
		// llamada: `esc_html( $texto_del_dueno )` sigue siendo el defecto, y por
		// eso se recorta el envoltorio y se vuelve a preguntar en vez de dar por
		// buena cualquier cosa que empiece por `esc_`.
		if ( 1 === preg_match( '/^(esc_html|esc_attr|esc_html__|esc_attr__|wp_kses_post|__)\s*\(\s*(.*)$/s', $argumento, $envoltorio ) ) {
			return $this->formato_es_seguro( $envoltorio[2] );
		}

		return false;
	}

	/** ¿Es una línea de comentario, de las que el proyecto mete dentro de la llamada? */
	private function es_comentario( string $linea ): bool {
		$linea = ltrim( $linea );

		return str_starts_with( $linea, '//' ) || str_starts_with( $linea, '*' ) || str_starts_with( $linea, '/*' );
	}

	/**
	 * @return array<int, array{fichero: string, linea: int, codigo: string}>
	 */
	private function formatos_variables(): array {
		$infractores = array();

		// `templates/` entra desde el 2026-09-06. El copy que ve el cliente vive
		// tanto en las clases como en las plantillas, y el barrido que sólo mira
		// `src/` deja fuera justo el fichero donde se escribe una frase nueva.
		foreach ( array( '/src', '/templates' ) as $sufijo ) {
			$this->barrer( dirname( __DIR__ ) . $sufijo, $infractores );
		}

		return $infractores;
	}

	/**
	 * @param string                                                  $raiz        Carpeta a barrer.
	 * @param array<int, array{fichero: string, linea: int, codigo: string}> $infractores Acumulador.
	 * @return void
	 */
	private function barrer( string $raiz, array &$infractores ): void {
		if ( ! is_dir( $raiz ) ) {
			return;
		}

		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}

			$relativa = str_replace( $raiz . '/', '', (string) $fichero->getPathname() );
			$lineas   = explode( "\n", (string) file_get_contents( (string) $fichero->getPathname() ) );

			foreach ( $lineas as $indice => $linea ) {
				preg_match_all( '/\b(s?printf)\s*\(/', $linea, $coincidencias, PREG_OFFSET_CAPTURE );

				foreach ( $coincidencias[0] ?? array() as $coincidencia ) {
					$desde = (int) $coincidencia[1] + strlen( (string) $coincidencia[0] );
					$resto = substr( $linea, $desde );

					// El estilo del proyecto parte el argumento a la línea
					// siguiente muy a menudo, y a veces mete un comentario en
					// medio explicando el formato. Mirar sólo esta línea marcaba
					// 130 llamadas correctas; no saltar comentarios, una más.
					// Las dos veces era el detector, no el código.
					// Y el envoltorio de escape puede abrirse en una línea y traer el
					// ternario tres más abajo (`printf(\n esc_html(\n $cond\n ?
					// 'uno'\n : 'otro'\n ), …`). Por eso, además de saltar las
					// líneas vacías, se acumulan hasta seis y se evalúa el bloque:
					// mirar una sola línea daba por variable un formato que es un
					// literal, y el candado sólo sirve si no grita en falso.
					$bloque = trim( $resto );
					for ( $extra = 1; $extra <= 6 && isset( $lineas[ $indice + $extra ] ); $extra++ ) {
						$bloque .= "\n" . $lineas[ $indice + $extra ];
					}
					if ( $this->formato_es_seguro( $bloque ) ) {
						continue;
					}

					$mirada = $indice;
					while ( isset( $lineas[ $mirada + 1 ] ) && $mirada - $indice < 6
						&& ( '' === trim( $resto ) || $this->es_comentario( $resto ) ) ) {
						++$mirada;
						$resto = $lineas[ $mirada ];
					}

					if ( $this->formato_es_seguro( $resto ) ) {
						continue;
					}

					$infractores[] = array(
						'fichero' => $relativa,
						'linea'   => $indice + 1,
						'codigo'  => trim( $linea ),
					);
				}
			}
		}
	}

	/**
	 * @testdox El formato de sprintf y printf es siempre un literal nuestro.
	 */
	public function test_no_owner_copy_reaches_a_format_string(): void {
		$infractores = $this->formatos_variables();

		$detalle = array_map(
			static fn( array $i ): string => $i['fichero'] . ':' . $i['linea'],
			$infractores
		);

		self::assertSame(
			array(),
			$detalle,
			'Un texto editable con un «%» dentro es un fatal en cuanto llega a un formato. '
				. 'Pásalo como argumento (`sprintf( \'%s …\', $texto )`) o usa str_replace con un marcador.'
		);
	}

	/**
	 * Control: si el detector no encontrara nunca nada, la prueba de arriba
	 * pasaría por vacío y nadie se enteraría. Se le da una forma que SÍ tiene que
	 * cazar.
	 *
	 * @testdox El detector reconoce un formato variable cuando lo ve.
	 */
	public function test_the_detector_actually_detects(): void {
		self::assertFalse( $this->formato_es_seguro( '$texto, $otro )' ), 'Una variable como formato es el defecto.' );
		self::assertFalse( $this->formato_es_seguro( 'get_option( "akb_copy_x" ) )' ), 'Una option como formato es el defecto exacto que pasó.' );
		self::assertFalse( $this->formato_es_seguro( 'EditionCopy::text( $k ) )' ), 'El texto del dueño por método tampoco vale.' );

		self::assertTrue( $this->formato_es_seguro( "'%s en Akibara', \$texto )" ), 'Un literal es la forma correcta.' );
		self::assertTrue( $this->formato_es_seguro( '"%d tomos", $n )' ), 'Con comillas dobles también.' );
		self::assertTrue(
			$this->formato_es_seguro( "( 1 === \$n ? '%d tomo' : '%d tomos' ), \$n )" ),
			'El ternario entre literales es legítimo: es el singular/plural que ya usa AdminDashboard.'
		);
		self::assertTrue(
			$this->formato_es_seguro( "esc_html( 'Hola %s,' ), \$nombre )" ),
			'Un literal escapado sigue siendo un literal: es la forma de las plantillas de correo.'
		);
		self::assertFalse(
			$this->formato_es_seguro( 'esc_html( $texto ), $n )' ),
			'Y el envoltorio no blanquea nada: dentro sigue habiendo una variable.'
		);
		self::assertFalse(
			$this->formato_es_seguro( 'esc_html( get_option( "akb_copy_x" ) ), $n )' ),
			'Ni aunque la variable venga por una option.'
		);
		self::assertTrue(
			$this->formato_es_seguro( "1 === \$n ? '%s pedido' : '%s pedidos', \$n )" ),
			'El ternario sin paréntesis es la forma de las plantillas de Mi cuenta.'
		);
		self::assertFalse(
			$this->formato_es_seguro( "\$copy . ( \$x ? 'a' : 'b' ), \$n )" ),
			'Un ternario DENTRO de una concatenación que empieza por variable no blanquea el formato.'
		);
	}
}
