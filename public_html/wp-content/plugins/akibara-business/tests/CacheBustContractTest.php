<?php
/**
 * Contrato de cache-bust: ningún asset first-party puede versionarse con `filemtime()`
 * PELADO.
 *
 * Este test existe porque el bug apareció DOS VECES en una misma jornada (2026-07-30/31):
 * primero en 14 sitios de akibara-business, y al barrer de verdad, en 15 sitios repartidos
 * por cuatro componentes. Un comentario no lo habría impedido; esto sí.
 *
 * ── Por qué `filemtime()` pelado es un bug y no un detalle ────────────────────
 * El empaquetador (`tools/akibara-releases/package-runtime.sh`) congela TODOS los mtimes
 * a 1980-01-01 para que el ZIP sea reproducible byte a byte. Si el despliegue preserva
 * esos mtimes —lo hace cualquier `rsync -a`/`tar -x` desde el ZIP extraído— entonces en
 * producción `filemtime()` es una CONSTANTE y `?ver=<filemtime>` deja de invalidar nada.
 *
 * Medido en producción antes del arreglo: esos assets se sirven con
 * `cache-control: max-age=31557600` (365,25 días) y SIN ETag, así que el único validador
 * es `Last-Modified`, que sale del mismo mtime congelado. Al vencer el año el navegador
 * revalidaba con `If-Modified-Since: 1980` y recibía **304**, renovando otro año. El
 * ciclo no terminaba solo: un cambio en esos archivos no lo veía nunca un visitante
 * recurrente.
 *
 * ── Qué exige el contrato ────────────────────────────────────────────────────
 * Que toda expresión que use `filemtime()` para construir una versión de asset lleve
 * delante un identificador de VERSIÓN (constante del componente, versión del theme, o un
 * helper que la resuelva). El `filemtime` detrás puede quedarse: aporta en local, donde
 * los mtimes son reales, y es inofensivo en producción.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class CacheBustContractTest extends TestCase {

	/**
	 * Tokens que, presentes en la misma expresión que `filemtime(`, acreditan que la
	 * versión del componente va delante.
	 */
	private const VERSION_TOKENS = array(
		'_VERSION',            // AKIBARA_BUSINESS_VERSION, AKIBARA_SEARCH_VERSION, …
		'ThemeAssetVersion',   // helper de business para archivos del theme
		'akibara_gp_asset_ver',// helper del theme
		'wp_get_theme',        // versión del theme resuelta a mano
	);

	/**
	 * Usos de `filemtime()` que NO son versionado de assets y por eso no aplican.
	 *
	 * Cada exención va con su motivo. Una exención sin motivo es deuda disfrazada.
	 *
	 * @var array<string, string>
	 */
	private const EXENTOS = array(
		// Es EL helper que implementa el contrato: resuelve el mtime en una sentencia y le
		// antepone la versión del theme en la siguiente (`$version . '.' . $modified`).
		// Cumple, solo que en dos pasos, y este análisis mira una expresión a la vez.
		'akibara-business/src/Utilities/ThemeAssetVersion.php' => 'implementación del contrato; antepone la versión en la sentencia siguiente',
		// El hermano del anterior para los assets del PROPIO plugin. Misma forma:
		// resuelve el mtime en una sentencia y antepone AKIBARA_BUSINESS_VERSION
		// en la siguiente.
		'akibara-business/src/Utilities/PluginAssetVersion.php' => 'implementación del contrato; antepone la versión en la sentencia siguiente',
		// No versiona ningún asset: compara mtimes de dos ARCHIVOS DE DATOS
		// (portada AVIF vs su derivada JPG) para decidir si la derivada debe
		// regenerarse. Las portadas las sube el pipeline, no el empaquetador,
		// así que su mtime en prod es real y la comparación es el contrato.
		'akibara-business/src/Modules/SeoSocial/SocialImageDerivatives.php' => 'comparación fuente-derivada del pipeline de imágenes; no construye ningún ?ver=',
		// Tampoco versiona assets: mide la edad de sus propios temporales de
		// recompresión para barrer los que dejó una corrida muerta.
		'akibara-business/src/Modules/ImageQuality/Recompressor.php' => 'edad de temporales propios de la recompresión de imágenes; no construye ningún ?ver=',
		// Clave de caché para derivadas de imágenes en correos electrónicos.
		// No construye ningún ?ver= ni encola assets web.
		'akibara-business/src/Modules/SeoSocial/EmailImageDerivatives.php' => 'clave de caché para derivadas de correo; no construye ningún ?ver=',
	);

	/**
	 * Exenciones fuera de `plugins/` (rutas relativas a wp-content).
	 *
	 * @var array<string, string>
	 */
	private const EXENTOS_ABSOLUTOS = array(
		// akibara_gp_asset_ver() resuelve el mtime en una sentencia y le antepone la
		// versión del theme en la siguiente (`$theme_version . '.' . $modified`). El
		// contrato se cumple, solo que en dos pasos, y este análisis mira una expresión
		// a la vez. Es EL helper del theme: si cambia, lo cazan sus propios tests.
		'themes/akibara-gp/inc/enqueue.php' => 'helper del theme; antepone la versión en la sentencia siguiente',
	);

	/**
	 * Raíz de wp-content dentro del árbol de trabajo.
	 */
	private function wp_content(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Componentes first-party sujetos al contrato.
	 *
	 * Los mu-plugins quedan FUERA a propósito: no viajan en ningún riel, se despliegan a
	 * mano y sus mtimes en producción son reales (verificado 2026-07-31), así que su
	 * `filemtime` pelado sí invalida. Si algún día entran al empaquetador, hay que
	 * sumarlos acá.
	 *
	 * @return array<int, string>
	 */
	private function componentes(): array {
		return array(
			'plugins/akibara-business',
			'plugins/akibara-search',
			'plugins/akibara-cart-checkout-blocks',
			'themes/akibara-gp',
		);
	}

	/**
	 * Devuelve las apariciones de `filemtime(` que NO llevan versión delante.
	 *
	 * Trabaja sobre la sentencia completa —desde el `;`, `,` o `(` anterior hasta el
	 * `filemtime`— y no sobre la línea, para que un enqueue partido en varias líneas
	 * (que es como están escritos casi todos) se evalúe entero.
	 *
	 * @return array<int, string> Descripciones "ruta:línea".
	 */
	private function infracciones(): array {
		$fallos = array();

		foreach ( $this->componentes() as $componente ) {
			$raiz = $this->wp_content() . '/' . $componente;
			if ( ! is_dir( $raiz ) ) {
				continue;
			}

			$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );

			foreach ( $iterador as $archivo ) {
				if ( ! $archivo->isFile() || 'php' !== $archivo->getExtension() ) {
					continue;
				}

				$ruta = str_replace( $this->wp_content() . '/plugins/', '', $archivo->getPathname() );
				$ruta = str_replace( $this->wp_content() . '/themes/', '', $ruta );

				// Los tests declaran expectativas sobre el contrato; no son el contrato.
				if ( false !== strpos( $archivo->getPathname(), '/tests/' ) ) {
					continue;
				}
				if ( isset( self::EXENTOS[ $ruta ] ) ) {
					continue;
				}
				$relativa = str_replace( $this->wp_content() . '/', '', $archivo->getPathname() );
				if ( isset( self::EXENTOS_ABSOLUTOS[ $relativa ] ) ) {
					continue;
				}

				$contenido = (string) file_get_contents( $archivo->getPathname() );
				if ( false === strpos( $contenido, 'filemtime' ) ) {
					continue;
				}

				$offset = 0;
				while ( false !== ( $pos = strpos( $contenido, 'filemtime', $offset ) ) ) {
					$offset = $pos + 9;

					// Ignora menciones en comentarios/docblocks.
					$inicio_linea = (int) strrpos( substr( $contenido, 0, $pos ), "\n" );
					$linea_txt    = ltrim( substr( $contenido, $inicio_linea, $pos - $inicio_linea ) );
					if ( '' !== $linea_txt && ( '*' === $linea_txt[0] || 0 === strpos( $linea_txt, '//' ) ) ) {
						continue;
					}

					// Retrocede hasta el inicio del ARGUMENTO que contiene este filemtime,
					// respetando paréntesis: así `( file_exists($p) ? … )` se lee entero
					// en vez de cortar en el `(` de file_exists.
					$corte  = 0;
					$profundidad = 0;
					for ( $i = $pos - 1; $i >= 0; $i-- ) {
						$c = $contenido[ $i ];
						if ( ')' === $c ) {
							$profundidad++;
							continue;
						}
						if ( '(' === $c ) {
							if ( $profundidad > 0 ) {
								$profundidad--;
								continue;
							}
							/*
							 * Paréntesis a nivel 0: hay que distinguir el de la LLAMADA
							 * contenedora —donde sí empieza el argumento— del de un simple
							 * AGRUPAMIENTO, que es parte de la misma expresión. Se mira qué
							 * lo precede: un identificador significa llamada
							 * (`wp_enqueue_script(`), un operador significa agrupamiento
							 * (`VERSION . ( … )`), y en ese caso hay que seguir retrocediendo
							 * o se pierde la versión que está justo antes.
							 */
							$j = $i - 1;
							while ( $j >= 0 && ( ' ' === $contenido[ $j ] || "\t" === $contenido[ $j ] || "\n" === $contenido[ $j ] ) ) {
								$j--;
							}
							$previo = $j >= 0 ? $contenido[ $j ] : '';
							if ( '' !== $previo && ( ctype_alnum( $previo ) || '_' === $previo ) ) {
								$corte = $i + 1; // paréntesis de la llamada contenedora
								break;
							}
							continue; // agrupamiento: sigue retrocediendo
						}
						if ( 0 === $profundidad && ( ',' === $c || ';' === $c || '=' === $c ) ) {
							$corte = $i + 1; // separador de argumento o de sentencia
							break;
						}
					}
					$expresion = substr( $contenido, $corte, $pos - $corte );

					/*
					 * No basta con que la versión APAREZCA en la expresión: tiene que ir
					 * DELANTE y concatenada. El bug original de cart-checkout-blocks era
					 *     file_exists($p) ? (string) filemtime($p) : COMPONENTE_VERSION
					 * donde la versión es el fallback para archivo ausente — o sea nunca
					 * llega a la URL cuando el archivo existe, que es siempre. Las formas
					 * correctas ponen la versión antes:
					 *     COMPONENTE_VERSION . '.' . filemtime($p)
					 *     COMPONENTE_VERSION . ( file_exists($p) ? '.' . filemtime($p) : '' )
					 */
					$tiene_version = false;
					foreach ( self::VERSION_TOKENS as $token ) {
						if ( false !== strpos( $expresion, $token ) && false !== strpos( $expresion, '.' ) ) {
							$tiene_version = true;
							break;
						}
					}

					if ( ! $tiene_version ) {
						$numero   = substr_count( substr( $contenido, 0, $pos ), "\n" ) + 1;
						$fallos[] = $ruta . ':' . $numero;
					}
				}
			}
		}

		return $fallos;
	}

	/**
	 * @testdox No first-party asset may be versioned with a bare filemtime()
	 */
	public function test_no_bare_filemtime_as_asset_version(): void {
		$fallos = $this->infracciones();

		self::assertSame(
			array(),
			$fallos,
			"Estos encolados versionan con filemtime() PELADO. En producción el mtime está\n"
			. "congelado en 1980 por el empaquetador, así que ese ?ver= es una CONSTANTE y el\n"
			. "cambio no lo ve nunca un visitante recurrente (sin ETag, la revalidación\n"
			. "devuelve 304 y renueva otro año de caché).\n\n"
			. "Antepón la versión del DUEÑO del archivo:\n"
			. "  - asset del propio plugin  → AKIBARA_<COMPONENTE>_VERSION . '.' . filemtime(...)\n"
			. "  - asset del theme          → ThemeAssetVersion::for_path(...) / akibara_gp_asset_ver(...)\n\n"
			. "Infracciones:\n  " . implode( "\n  ", $fallos )
		);
	}

	/**
	 * El test anterior solo sirve si de verdad mira los cuatro componentes: si una ruta
	 * se renombra, `is_dir()` falla en silencio y el contrato pasa a no verificar nada.
	 *
	 * @testdox The contract actually scans all four first-party components
	 */
	public function test_contract_scans_every_component(): void {
		foreach ( $this->componentes() as $componente ) {
			self::assertDirectoryExists(
				$this->wp_content() . '/' . $componente,
				"El contrato de cache-bust cree estar vigilando {$componente} y ese directorio no existe: "
				. 'renómbralo en componentes() o el test pasa sin mirar nada.'
			);
		}
	}

	/**
	 * La MITAD SIMÉTRICA del contrato: ningún encolado de un asset propio puede
	 * versionarse con la constante de versión PELADA.
	 *
	 * El test de arriba era tuerto: prohibía `filemtime()` sin versión delante, y
	 * no podía ver el caso opuesto —versión sin `filemtime` detrás—, que en
	 * akibara-business eran 19 encolados, 8 de ellos de FRONT y cacheados un año
	 * por `.htaccess`. Consecuencia: editar uno de esos archivos sin bumpear la
	 * versión del plugin dejaba al visitante recurrente con la copia vieja
	 * durante todo ese año, en silencio.
	 *
	 * Alcance: por ahora sólo akibara-business, que es donde vive
	 * `PluginAssetVersion`. Los otros tres componentes entran cuando cada uno
	 * adopte su propio helper (la mitad negativa de arriba sí los cubre a los
	 * cuatro desde el principio).
	 *
	 * @testdox No first-party enqueue may ship a bare version constant
	 */
	public function test_no_bare_version_constant_as_asset_version(): void {
		$fallos = $this->encolados_sin_filemtime();

		self::assertSame(
			array(),
			$fallos,
			"Estos encolados versionan con la constante de versión PELADA, sin filemtime detrás.\n"
			. "Un cambio en el archivo que no venga con un bump de versión no invalida nada, y\n"
			. ".htaccess cachea estos assets un año.\n\n"
			. "Usa el helper del componente:\n"
			. "  - asset del propio plugin → PluginAssetVersion::for_path( 'assets/…' )\n"
			. "  - asset del theme         → ThemeAssetVersion::for_path( 'assets/…' )\n\n"
			. "Infracciones:\n  " . implode( "\n  ", $fallos )
		);
	}

	/**
	 * Encolados de assets propios cuya versión no depende del archivo.
	 *
	 * @return array<int, string> Descripciones "ruta:línea".
	 */
	private function encolados_sin_filemtime(): array {
		$fallos = array();
		$raiz   = $this->wp_content() . '/plugins/akibara-business/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );
		foreach ( $iterator as $archivo ) {
			if ( ! $archivo->isFile() || 'php' !== $archivo->getExtension() ) {
				continue;
			}

			$contenido = (string) file_get_contents( $archivo->getPathname() );
			$relativa  = 'akibara-business/src' . substr( $archivo->getPathname(), strlen( $raiz ) );

			foreach ( $this->llamadas_de_encolado( $contenido ) as $llamada ) {
				// Sólo assets PROPIOS: los de terceros los versiona su dueño.
				if ( false === strpos( $llamada['src'], '_URL' ) ) {
					continue;
				}
				if ( $this->version_depende_del_archivo( $llamada['version'] ) ) {
					continue;
				}

				$fallos[] = $relativa . ':' . ( substr_count( substr( $contenido, 0, $llamada['pos'] ), "\n" ) + 1 );
			}
		}

		sort( $fallos );

		return $fallos;
	}

	/**
	 * ¿La expresión de versión cambia cuando cambia el archivo?
	 */
	private function version_depende_del_archivo( string $version ): bool {
		foreach ( array( 'AssetVersion::for_path', 'akibara_gp_asset_ver', 'filemtime' ) as $token ) {
			if ( false !== strpos( $version, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extrae los `wp_enqueue_*`/`wp_register_*` con sus argumentos 2 (src) y 4 (versión).
	 *
	 * @return array<int, array{pos: int, src: string, version: string}>
	 */
	private function llamadas_de_encolado( string $contenido ): array {
		$llamadas = array();

		foreach ( array( 'wp_enqueue_style', 'wp_enqueue_script', 'wp_register_style', 'wp_register_script' ) as $funcion ) {
			$desde = 0;
			while ( false !== ( $pos = strpos( $contenido, $funcion . '(', $desde ) ) ) {
				$abre  = $pos + strlen( $funcion );
				$cierra = $this->cierre_de_parentesis( $contenido, $abre );
				$desde  = $abre + 1;
				if ( null === $cierra ) {
					continue;
				}

				$args = $this->argumentos( substr( $contenido, $abre + 1, $cierra - $abre - 1 ) );
				if ( count( $args ) < 4 ) {
					continue;
				}

				$llamadas[] = array(
					'pos'     => $pos,
					'src'     => $args[1],
					'version' => $args[3],
				);
			}
		}

		return $llamadas;
	}

	/**
	 * Posición del `)` que cierra el `(` en `$abre`, o null.
	 */
	private function cierre_de_parentesis( string $contenido, int $abre ): ?int {
		$nivel  = 0;
		$comilla = null;
		$largo  = strlen( $contenido );

		for ( $i = $abre; $i < $largo; $i++ ) {
			$c = $contenido[ $i ];
			if ( null !== $comilla ) {
				if ( '\\' === $c ) {
					++$i;
					continue;
				}
				if ( $c === $comilla ) {
					$comilla = null;
				}
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$comilla = $c;
				continue;
			}
			if ( '(' === $c ) {
				++$nivel;
			} elseif ( ')' === $c ) {
				--$nivel;
				if ( 0 === $nivel ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Parte una lista de argumentos por las comas de nivel 0.
	 *
	 * @return array<int, string>
	 */
	private function argumentos( string $interior ): array {
		$args    = array();
		$actual  = '';
		$nivel   = 0;
		$comilla = null;
		$largo   = strlen( $interior );

		for ( $i = 0; $i < $largo; $i++ ) {
			$c = $interior[ $i ];
			if ( null !== $comilla ) {
				$actual .= $c;
				if ( '\\' === $c && $i + 1 < $largo ) {
					$actual .= $interior[ ++$i ];
					continue;
				}
				if ( $c === $comilla ) {
					$comilla = null;
				}
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$comilla = $c;
				$actual .= $c;
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				++$nivel;
			} elseif ( ')' === $c || ']' === $c ) {
				--$nivel;
			}
			if ( ',' === $c && 0 === $nivel ) {
				$args[] = $actual;
				$actual = '';
				continue;
			}
			$actual .= $c;
		}
		$args[] = $actual;

		return $args;
	}

	/**
	 * Las exenciones tienen que seguir existiendo; si no, son ruido que oculta el día que
	 * alguien reintroduzca el patrón en esa misma ruta.
	 *
	 * @testdox Every documented exemption still points at a real file
	 */
	public function test_exemptions_are_not_stale(): void {
		foreach ( self::EXENTOS as $ruta => $motivo ) {
			self::assertFileExists(
				$this->wp_content() . '/plugins/' . $ruta,
				"La exención '{$ruta}' ({$motivo}) apunta a un archivo que ya no existe. Bórrala."
			);
		}
		foreach ( self::EXENTOS_ABSOLUTOS as $ruta => $motivo ) {
			self::assertFileExists(
				$this->wp_content() . '/' . $ruta,
				"La exención '{$ruta}' ({$motivo}) apunta a un archivo que ya no existe. Bórrala."
			);
		}
	}
}
