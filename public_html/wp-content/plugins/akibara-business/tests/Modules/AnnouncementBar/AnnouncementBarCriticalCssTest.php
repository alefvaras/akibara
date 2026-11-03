<?php
/**
 * El CSS crítico en línea de la barra no puede divergir de su hoja.
 *
 * La barra imprime ~3 KB de CSS crítico dentro del PHP en CADA página del
 * sitio, y esa copia ya había derivado: el anillo de foco decía `#FFD60A`
 * mientras `announcement-bar.css` —y las otras 48 apariciones del token en el
 * proyecto— decían `#FFD700`. Nadie lo ve mirando la página: el valor sólo
 * asoma medio segundo, antes de que llegue la hoja combinada.
 *
 * Este test compara regla a regla las dos fuentes y sólo tolera las
 * divergencias declaradas abajo, que son las que el bloque crítico omite a
 * propósito (los desplazamientos de la animación de entrada: en el primer
 * frame el mensaje tiene que estar YA en su sitio).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

final class AnnouncementBarCriticalCssTest extends TestCase {

	/**
	 * Declaraciones que el bloque crítico dice distinto A PROPÓSITO.
	 *
	 * Clave: `contexto|selector|propiedad`. Valor: por qué diverge.
	 *
	 * @var array<string, string>
	 */
	private const DIVERGENCIAS_DELIBERADAS = array(
		'|.akb-ann-msg::after|transform'                 => 'La hoja entra desde translateX(28px); en el primer frame el mensaje ya está en su sitio.',
		'|.akb-ann-msg-inner|transform'                  => 'Mismo desplazamiento de entrada que el ::after.',
		'|.akb-ann-msg.akb-on::after|transform'          => 'El estado final de la hoja incluye translateX(0), que acá es el punto de partida.',
		'|.akb-ann-msg.akb-on .akb-ann-msg-inner|transform' => 'Igual que el ::after activo.',
	);

	private function critical_css(): string {
		return (string) ( new ReflectionClassConstant( AnnouncementBar::class, 'CRITICAL_CSS' ) )->getValue();
	}

	private function stylesheet(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/css/announcement-bar.css'
		);
	}

	/**
	 * Convierte CSS en `contexto|selector` => [propiedad => valor], con el
	 * contexto vacío para las reglas de nivel superior y la query como contexto
	 * dentro de un `@media`. Los valores se normalizan (espacios, ceros a la
	 * izquierda, comillas) para que la minificación no cuente como divergencia.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function parse( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$medias = array();
		$offset = 0;
		while ( 1 === preg_match( '/@media([^{]*)\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$abre  = (int) $m[0][1] + strlen( $m[0][0] ) - 1;
			$nivel = 0;
			$largo = strlen( $css );
			for ( $i = $abre; $i < $largo; $i++ ) {
				if ( '{' === $css[ $i ] ) {
					++$nivel;
				} elseif ( '}' === $css[ $i ] ) {
					--$nivel;
					if ( 0 === $nivel ) {
						$medias[] = array(
							'query' => '@media' . preg_replace( '/\s+/', '', (string) $m[1][0] ),
							'body'  => substr( $css, $abre + 1, $i - $abre - 1 ),
							'desde' => (int) $m[0][1],
							'hasta' => $i + 1,
						);
						break;
					}
				}
			}
			$offset = isset( $medias[ count( $medias ) - 1 ] ) && $medias[ count( $medias ) - 1 ]['desde'] === (int) $m[0][1]
				? $medias[ count( $medias ) - 1 ]['hasta']
				: (int) $m[0][1] + strlen( $m[0][0] );
		}

		$reglas = array();
		$resto  = $css;
		foreach ( array_reverse( $medias ) as $media ) {
			$resto = substr( $resto, 0, $media['desde'] ) . substr( $resto, $media['hasta'] );
		}

		$this->collect( $resto, '', $reglas );
		foreach ( $medias as $media ) {
			$this->collect( $media['body'], $media['query'], $reglas );
		}

		return $reglas;
	}

	/**
	 * @param array<string, array<string, string>> $reglas Acumulador.
	 */
	private function collect( string $bloque, string $contexto, array &$reglas ): void {
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $bloque, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$declaraciones = array();
			foreach ( explode( ';', $match[2] ) as $declaracion ) {
				if ( ! str_contains( $declaracion, ':' ) ) {
					continue;
				}
				[ $propiedad, $valor ]                 = explode( ':', $declaracion, 2 );
				$declaraciones[ trim( $propiedad ) ] = $this->normalize( $valor );
			}

			foreach ( explode( ',', $match[1] ) as $selector ) {
				$selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
				if ( '' === $selector ) {
					continue;
				}
				$clave            = $contexto . '|' . $selector;
				$reglas[ $clave ] = array_merge( $reglas[ $clave ] ?? array(), $declaraciones );
			}
		}
	}

	private function normalize( string $valor ): string {
		$valor = trim( (string) preg_replace( '/\s+/', '', $valor ) );
		$valor = str_replace( "'", '"', $valor );
		// `.04em` y `0.04em` son el mismo valor; sólo cambia el minificador.
		$valor = (string) preg_replace( '/(?<![\d.])0\.(\d)/', '.$1', $valor );

		return strtolower( $valor );
	}

	public function test_every_critical_rule_matches_the_stylesheet(): void {
		$critico = $this->parse( $this->critical_css() );
		$hoja    = $this->parse( $this->stylesheet() );

		self::assertNotSame( array(), $critico, 'El bloque crítico no puede quedar vacío.' );

		$divergencias = array();
		foreach ( $critico as $clave => $declaraciones ) {
			self::assertArrayHasKey(
				$clave,
				$hoja,
				sprintf( 'El bloque crítico estiliza «%s» y la hoja no lo declara.', $clave )
			);

			foreach ( $declaraciones as $propiedad => $valor ) {
				if ( isset( self::DIVERGENCIAS_DELIBERADAS[ $clave . '|' . $propiedad ] ) ) {
					continue;
				}
				$en_hoja = $hoja[ $clave ][ $propiedad ] ?? '(ausente)';
				if ( $en_hoja !== $valor ) {
					$divergencias[] = sprintf( '%s|%s: crítico «%s» vs hoja «%s»', $clave, $propiedad, $valor, $en_hoja );
				}
			}
		}

		self::assertSame(
			array(),
			$divergencias,
			"El CSS crítico en línea derivó de announcement-bar.css:\n" . implode( "\n", $divergencias )
		);
	}

	/**
	 * Las excepciones tienen que seguir siendo excepciones: una entrada de la
	 * lista que ya no diverge es ruido que tapa la próxima deriva de verdad.
	 */
	public function test_the_declared_divergences_are_all_real(): void {
		$critico = $this->parse( $this->critical_css() );
		$hoja    = $this->parse( $this->stylesheet() );

		foreach ( array_keys( self::DIVERGENCIAS_DELIBERADAS ) as $entrada ) {
			$partes    = explode( '|', $entrada );
			$propiedad = (string) array_pop( $partes );
			$clave     = implode( '|', $partes );

			self::assertArrayHasKey( $clave, $critico, $entrada );
			self::assertArrayHasKey( $propiedad, $critico[ $clave ], $entrada );
			self::assertNotSame(
				$hoja[ $clave ][ $propiedad ] ?? '(ausente)',
				$critico[ $clave ][ $propiedad ],
				sprintf( '«%s» ya no diverge: sácalo de la lista de excepciones.', $entrada )
			);
		}
	}
}
