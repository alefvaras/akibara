<?php
/**
 * Candado: las options de Rank Math se llaman `rank-math-options-<módulo>`.
 *
 * Dos veces en un día (2026-09-20) un guard leyó `get_option( 'rank_math_…' )`
 * —`rank_math_sitemap` en el `lastmod` del índice (revisión R8, H1) y
 * `rank_math_titles` en la meta de las 109 landings de autor
 * (R9-plantilla-autor-inalcanzable)— y como esas options no existen, el guard
 * caía siempre en el default y la promesa del docblock nunca corría. El
 * registro real está en `seo-by-rank-math/includes/class-settings.php`
 * (`add_options( 'titles', 'rank-math-options-titles' )`, ídem `sitemap` y
 * `general`); `rank_math_<módulo>` es sólo el alias de telemetría y de las
 * capabilities. Preferir `\RankMath\Helper::get_settings( 'módulo.clave' )`.
 *
 * Se recorre con el tokenizador de PHP —no con un regex sobre el texto— para
 * que un comentario que cite la llamada no ponga la suite en rojo, y se cubren
 * los cuatro plugins propios y los mu-plugins (revisión R9, 2.6).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class RankMathOptionsSeLlamanConGuionesTest extends TestCase {

	private const LECTORAS = array( 'get_option', 'get_site_option', 'get_network_option' );

	/**
	 * @testdox Ningún fichero propio lee una option `rank_math_*` con guiones bajos.
	 */
	public function test_ninguna_lectura_de_option_rank_math_con_guion_bajo(): void {
		$culpables = array();
		foreach ( self::raices() as $raiz ) {
			foreach ( self::ficheros_php( $raiz ) as $fichero ) {
				foreach ( self::lecturas_rank_math_con_guion_bajo( (string) file_get_contents( $fichero ) ) as $literal ) {
					$culpables[] = substr( $fichero, strlen( dirname( $raiz ) ) + 1 ) . ' → ' . $literal;
				}
			}
		}

		self::assertSame( array(), $culpables, 'Rank Math guarda sus ajustes en `rank-math-options-<módulo>`; `rank_math_<módulo>` no existe como option.' );
	}

	/**
	 * El candado se prueba a sí mismo: distingue la llamada real de un comentario
	 * que la cite, y ve las tres lectoras con cualquier espaciado.
	 *
	 * @testdox El candado ignora comentarios y cadenas sueltas, y ve las tres lectoras.
	 */
	public function test_el_candado_distingue_codigo_de_comentarios(): void {
		$codigo = "<?php\n// antes leía get_option( 'rank_math_titles' )\n/** get_site_option('rank_math_sitemap') */\n\$x = 'get_option( \\'rank_math_general\\' )';\n\$a = get_option ( \"rank_math_titles\", array() );\n\$b = get_site_option(\n\t'rank_math_sitemap'\n);\n\$c = get_network_option( 1, 'rank_math_general' );\n\$d = get_option( 'rank-math-options-titles' );\n\$e = get_option( \$nombre );\n";

		self::assertSame(
			array( "get_option( 'rank_math_titles' )", "get_site_option( 'rank_math_sitemap' )", "get_network_option( 'rank_math_general' )" ),
			self::lecturas_rank_math_con_guion_bajo( $codigo )
		);
	}

	/**
	 * `src/` de los cuatro plugins propios y el directorio de mu-plugins.
	 *
	 * @return array<int, string>
	 */
	private static function raices(): array {
		$content = dirname( __DIR__, 2 );
		$raices  = array();
		foreach ( array( 'akibara-business', 'akibara-search', 'akibara-rewards', 'akibara-cart-checkout-blocks' ) as $plugin ) {
			$raices[] = $content . '/plugins/' . $plugin . '/src';
		}
		$raices[] = $content . '/mu-plugins';

		return array_values( array_filter( $raices, 'is_dir' ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function ficheros_php( string $raiz ): array {
		$ficheros = array();
		$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterador as $fichero ) {
			$ruta = (string) $fichero;
			if ( 'php' === $fichero->getExtension() && false === strpos( $ruta, '/vendor/' ) && false === strpos( $ruta, '/node_modules/' ) ) {
				$ficheros[] = $ruta;
			}
		}
		sort( $ficheros );

		return $ficheros;
	}

	/**
	 * Llamadas a una lectora de options cuyo PRIMER literal de cadena (o el
	 * segundo, en `get_network_option`) empieza por `rank_math_`.
	 *
	 * @return array<int, string> `lectora( 'literal' )`, en orden de aparición.
	 */
	private static function lecturas_rank_math_con_guion_bajo( string $codigo ): array {
		$tokens    = token_get_all( $codigo );
		$hallazgos = array();
		$n         = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( strtolower( $t[1] ), self::LECTORAS, true ) ) {
				continue;
			}
			$lectora = strtolower( $t[1] );
			$j       = self::siguiente_significativo( $tokens, $i + 1 );
			if ( null === $j || '(' !== $tokens[ $j ] ) {
				continue;
			}
			$j = self::siguiente_significativo( $tokens, $j + 1 );
			if ( 'get_network_option' === $lectora ) {
				// Primer argumento: el id de red; el nombre viene después de la coma.
				while ( null !== $j && ',' !== $tokens[ $j ] ) {
					$j = self::siguiente_significativo( $tokens, $j + 1 );
				}
				$j = null === $j ? null : self::siguiente_significativo( $tokens, $j + 1 );
			}
			if ( null === $j || ! is_array( $tokens[ $j ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
				continue;
			}
			$literal = trim( $tokens[ $j ][1], '\'"' );
			if ( 0 === strpos( $literal, 'rank_math_' ) ) {
				$hallazgos[] = $lectora . "( '" . $literal . "' )";
			}
		}

		return $hallazgos;
	}

	/**
	 * @param array<int, mixed> $tokens Tokens.
	 * @return int|null Índice del siguiente token que no es espacio ni comentario.
	 */
	private static function siguiente_significativo( array $tokens, int $desde ): ?int {
		$n = count( $tokens );
		for ( $i = $desde; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $i;
		}

		return null;
	}
}
