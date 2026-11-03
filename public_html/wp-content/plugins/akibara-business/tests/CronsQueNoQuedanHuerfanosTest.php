<?php
/**
 * Desactivar el plugin no puede dejar eventos de WP-Cron sin quien los atienda.
 *
 * Un evento huérfano se dispara igual: WordPress lo saca de la cola, no
 * encuentra callback y lo vuelve a agendar si era recurrente. No rompe nada
 * visible, y por eso vive años. Pasa de verdad: el par Business/Search se
 * desactiva en cada despliegue del riel.
 *
 * Hallazgo S-dfc01561: `akibara_business_cleanup_collection_owner_fence` se
 * agenda con el nombre de la option como ARGUMENTO, y
 * `wp_clear_scheduled_hook()` sin argumentos no retira esos: sólo
 * `wp_unschedule_hook()`.
 *
 * Hallazgos S-0e169ea5, S-45f49941, S-66f38031 y S-d7f87778 (2026-09-19): la
 * desactivación, la desinstalación y la tarjeta de salud llevaban cada una su
 * copia de la lista, y las tres divergieron —6 entradas contra 8, la valla de
 * la colección sin retirar, 3 crons vigilados de 8—. Ahora la lista es UNA,
 * `Utilities\OwnCrons`, y este candado la compara con lo que el plugin agenda
 * de verdad, en los dos sentidos.
 *
 * Segunda pasada de S-0e169ea5 (revisión independiente, 2026-09-19), dos cosas:
 *
 *   - El arreglo unificó WP-Cron y dejó a mano, en los mismos dos ficheros, la
 *     lista de Action Scheduler —la vía NORMAL del trabajo del plugin—, ya
 *     divergida: desactivar retiraba 3 grupos, desinstalar 5, y la purga de
 *     Cloudflare ninguna. Y este candado no lo veía: sólo prohibía las
 *     funciones de WP-Cron. Ahora la cola también vive en `OwnCrons::QUEUE` y
 *     se compara con `src/` igual que los crons.
 *   - El candado prohibía que la lista nombrara un hook que ya nadie agenda,
 *     que es justo lo que hay que hacer al borrar un módulo: dejar su hook en
 *     la lista de retirada para barrer lo que quedó en los sitios ya
 *     instalados. Para eso existe `OwnCrons::RETIRED`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use Akibara\Business\Utilities\OwnCrons;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CronsQueNoQuedanHuerfanosTest extends TestCase {

	/**
	 * Funciones de Action Scheduler que AGENDAN, con la posición del hook y la
	 * del grupo entre sus argumentos.
	 */
	private const AGENDAN = array(
		'as_enqueue_async_action'      => array( 0, 2 ),
		'as_schedule_single_action'    => array( 1, 3 ),
		'as_schedule_recurring_action' => array( 2, 4 ),
		'as_schedule_cron_action'      => array( 2, 4 ),
	);

	/** @var array<int, string> Hooks retirados de WP-Cron. */
	private array $retirados = array();

	/** @var array<int, array{0: string, 1: array<mixed>, 2: string}> Llamadas a `as_unschedule_all_actions()`. */
	private array $cola = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Los dos dobles van AQUÍ y no en cada prueba: `function_exists()` da
		// true por contagio en cuanto una prueba define la función, y
		// `unschedule_all()` pregunta justamente eso antes de tocar la cola.
		$this->retirados = array();
		$this->cola      = array();
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) {
				$this->retirados[] = $hook;
				return 0;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( $hook, $args = array(), $group = '' ) {
				$this->cola[] = array( $hook, $args, $group );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function raiz(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Hooks que el plugin agenda de verdad, leídos de `src/`.
	 *
	 * @param bool $solo_recurrentes Sólo los de `wp_schedule_event()`.
	 * @return array<int, string>
	 */
	private function hooks_agendados( bool $solo_recurrentes = false ): array {
		$hooks    = array();
		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->raiz() . '/src' ) );
		$llamada  = $solo_recurrentes ? 'wp_schedule_event' : 'wp_schedule(?:_single)?_event';

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$codigo = (string) file_get_contents( $fichero->getPathname() );

			// Sólo las constantes que nombran el HOOK: en la misma llamada viajan
			// el instante (`HOUR_IN_SECONDS`) y la frecuencia (`SCHEDULE`), que
			// no son hooks y no se retiran de ninguna cola.
			if ( ! preg_match_all( '/\b' . $llamada . '\([^;]*?self::([A-Z_]*HOOK[A-Z_]*)\s*[,)]/', $codigo, $m ) ) {
				continue;
			}

			foreach ( $m[1] as $constante ) {
				if ( preg_match( "/const\s+" . preg_quote( $constante, '/' ) . "\s*=\s*'([a-z0-9_]+)'/i", $codigo, $valor ) ) {
					$hooks[] = $valor[1];
				}
			}
		}

		$hooks = array_values( array_unique( $hooks ) );
		sort( $hooks );

		return $hooks;
	}

	/**
	 * Código de un fichero sin comentarios: un candado de fuente que no los quita
	 * se da por satisfecho con la frase que explica lo que ya no se hace.
	 */
	private function codigo_sin_comentarios( string $fichero ): string {
		$ruta   = '/' === $fichero[0] ? $fichero : $this->raiz() . '/' . $fichero;
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( $ruta ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		return $codigo;
	}

	/**
	 * Lo que el plugin agenda de verdad en Action Scheduler, leído de `src/`:
	 * «grupo · hook» por cada llamada que agenda.
	 *
	 * Lee los ARGUMENTOS de cada llamada y los resuelve hasta su literal, porque
	 * el hook y el grupo casi nunca se escriben a mano: son constantes de la
	 * clase, de otra clase (`SeriesFollow::AS_GROUP`), una variable local o un
	 * método que devuelve la constante. Lo que no sabe leer NO se lo salta:
	 * falla, que un candado que ignora lo que no entiende no mide nada.
	 *
	 * @return array<int, string>
	 */
	private function cola_agendada(): array {
		$pares = array();

		foreach ( $this->ficheros_src() as $ruta ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $ruta ) ),
					static fn( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true )
				)
			);

			$antes = '';
			foreach ( $tokens as $i => $token ) {
				$texto  = is_array( $token ) ? $token[1] : $token;
				$antes .= $texto;
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
					continue;
				}

				$funcion = ltrim( $texto, '\\' );
				$previo  = $tokens[ $i - 1 ] ?? null;
				if ( ! isset( self::AGENDAN[ $funcion ] )
					|| '(' !== ( $tokens[ $i + 1 ] ?? null )
					|| ( is_array( $previo ) && in_array( $previo[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) ) {
					continue;
				}

				$donde                = substr( $ruta, strlen( $this->raiz() ) + 1 ) . ':' . $token[2];
				$argumentos           = $this->argumentos( $tokens, $i + 1 );
				list( $en_hook, $en_grupo ) = self::AGENDAN[ $funcion ];

				$hook  = $this->literal( $argumentos[ $en_hook ] ?? '', $ruta, $antes );
				$grupo = $this->literal( $argumentos[ $en_grupo ] ?? '', $ruta, $antes );

				self::assertNotNull( $hook, "{$donde}: no sé leer el hook de `{$funcion}()`. Si el candado no lo entiende, no lo vigila: escríbelo como constante de clase." );
				self::assertNotNull( $grupo, "{$donde}: `{$funcion}()` agenda sin grupo, o con uno que no sé leer. Toda acción del plugin lleva grupo: es lo que permite retirarlas juntas." );
				self::assertNotSame( '', $grupo, "{$donde}: `{$funcion}()` agenda con el grupo vacío." );

				$pares[] = "{$grupo} · {$hook}";
			}
		}

		$pares = array_values( array_unique( $pares ) );
		sort( $pares );

		return $pares;
	}

	/**
	 * @return array<int, string> Rutas absolutas de los `.php` de `src/`.
	 */
	private function ficheros_src(): array {
		$rutas = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->raiz() . '/src' ) ) as $fichero ) {
			if ( $fichero->isFile() && 'php' === $fichero->getExtension() ) {
				$rutas[] = $fichero->getPathname();
			}
		}
		sort( $rutas );

		return $rutas;
	}

	/**
	 * Argumentos de una llamada, como texto, partidos por las comas de primer nivel.
	 *
	 * @param array<int, mixed> $tokens Tokens sin comentarios ni espacios.
	 * @param int               $abre   Índice del paréntesis que abre la llamada.
	 * @return array<int, string>
	 */
	private function argumentos( array $tokens, int $abre ): array {
		$argumentos = array( '' );
		$nivel      = 0;
		$total      = count( $tokens );

		for ( $i = $abre; $i < $total; $i++ ) {
			$texto = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];

			if ( in_array( $texto, array( '(', '[', '{' ), true ) ) {
				++$nivel;
				if ( 1 === $nivel ) {
					continue;
				}
			} elseif ( in_array( $texto, array( ')', ']', '}' ), true ) ) {
				--$nivel;
				if ( 0 === $nivel ) {
					break;
				}
			} elseif ( ',' === $texto && 1 === $nivel ) {
				$argumentos[] = '';
				continue;
			}

			$argumentos[ count( $argumentos ) - 1 ] .= $texto;
		}

		return $argumentos;
	}

	/**
	 * Resuelve una expresión de `src/` hasta su literal, o null si no sabe.
	 *
	 * @param string $expresion Texto del argumento, sin espacios.
	 * @param string $ruta      Fichero donde está escrita.
	 * @param string $antes     Código del fichero anterior a la llamada, para las variables.
	 */
	private function literal( string $expresion, string $ruta, string $antes ): ?string {
		if ( preg_match( "/^'([^']*)'$/", $expresion, $m ) ) {
			return $m[1];
		}

		if ( preg_match( '/^([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)$/', $expresion, $m ) ) {
			$fichero = in_array( $m[1], array( 'self', 'static' ), true ) ? $ruta : $this->fichero_de_la_clase( $m[1], $ruta );
			if ( null !== $fichero && preg_match( '/\bconst\s+' . $m[2] . "\s*=\s*'([^']*)'\s*;/", $this->codigo_sin_comentarios( $fichero ), $valor ) ) {
				return $valor[1];
			}
			return null;
		}

		if ( preg_match( '/^(?:self|static)::([a-z_][A-Za-z0-9_]*)\(\)$/', $expresion, $m ) ) {
			if ( preg_match( '/function\s+' . $m[1] . '\s*\(\s*\)[^{]*\{\s*return\s+([^;]+);/', $this->codigo_sin_comentarios( $ruta ), $valor ) ) {
				return $this->literal( (string) preg_replace( '/\s+/', '', $valor[1] ), $ruta, '' );
			}
			return null;
		}

		if ( preg_match( '/^\$[A-Za-z_][A-Za-z0-9_]*$/', $expresion )
			&& preg_match_all( '/' . preg_quote( $expresion, '/' ) . '=([^;=][^;]*);/', $antes, $asignaciones ) ) {
			return $this->literal( (string) end( $asignaciones[1] ), $ruta, '' );
		}

		return null;
	}

	/**
	 * Fichero de `src/` que declara una clase nombrada desde otro: el vecino de
	 * directorio si lo hay (mismo namespace), y si no el único con ese nombre.
	 */
	private function fichero_de_la_clase( string $clase, string $desde ): ?string {
		$nombre  = substr( '\\' . $clase, (int) strrpos( '\\' . $clase, '\\' ) + 1 ) . '.php';
		$vecino  = dirname( $desde ) . '/' . $nombre;
		if ( is_file( $vecino ) ) {
			return $vecino;
		}

		$candidatos = array_values( array_filter( $this->ficheros_src(), static fn( string $ruta ): bool => basename( $ruta ) === $nombre ) );

		return 1 === count( $candidatos ) ? $candidatos[0] : null;
	}

	/**
	 * `OwnCrons::QUEUE` en la misma forma que `cola_agendada()`.
	 *
	 * @return array<int, string>
	 */
	private function cola_declarada(): array {
		$pares = array();
		foreach ( OwnCrons::QUEUE as $grupo => $hooks ) {
			foreach ( $hooks as $hook ) {
				$pares[] = "{$grupo} · {$hook}";
			}
		}
		sort( $pares );

		return $pares;
	}

	/** @testdox La lista única nombra cada hook que el plugin agenda, y sólo los retirados pueden no agendarse ya. */
	public function test_la_lista_unica_es_exactamente_lo_que_se_agenda(): void {
		$agendados = $this->hooks_agendados();
		self::assertNotEmpty( $agendados, 'Si no se detecta ningún hook, este candado no mide nada.' );

		// Los VIVOS: lo que el plugin dice que agenda hoy. Los retirados se miden
		// aparte, porque su regla es la contraria.
		$vivos = array_merge( array_keys( OwnCrons::RECURRING ), OwnCrons::SINGLE );
		sort( $vivos );

		self::assertSame(
			array(),
			array_values( array_diff( $agendados, $vivos ) ),
			'Hay eventos que el plugin agenda y la lista única no nombra: siguen vivos tras desactivar o desinstalar, disparando un hook sin callback.'
		);
		self::assertSame(
			array(),
			array_values( array_diff( $vivos, $agendados ) ),
			'RECURRING o SINGLE nombran hooks que ya nadie agenda. Si el módulo se borró, el hook no se quita: se MUDA a `OwnCrons::RETIRED` con la versión, para seguir barriéndolo de los sitios que ya lo tenían agendado. (Y si era recurrente, aquí además haría que la tarjeta avisara de un cron que ya no tiene que existir.)'
		);
		self::assertSame( count( $vivos ), count( array_unique( $vivos ) ), 'Un hook repetido entre RECURRING y SINGLE.' );
	}

	/**
	 * La puerta que el candado tenía cerrada (S-0e169ea5, revisión independiente,
	 * 2026-09-19): exigía que la lista fuera EXACTAMENTE lo que se agenda, así
	 * que al borrar un módulo obligaba a quitar su hook en el mismo cambio, y
	 * desde ahí ni desactivar ni desinstalar barrían el evento que los sitios ya
	 * tenían en la cola. La regla de un retirado es la inversa de la de un vivo.
	 *
	 * @testdox Un hook retirado no lo agenda nadie, no está entre los vivos y dice desde qué versión.
	 */
	public function test_los_retirados_son_de_verdad_retirados(): void {
		$vivos = array_merge( array_keys( OwnCrons::RECURRING ), OwnCrons::SINGLE );
		foreach ( OwnCrons::QUEUE as $hooks ) {
			$vivos = array_merge( $vivos, $hooks );
		}

		$agendados = $this->hooks_agendados();
		foreach ( $this->cola_agendada() as $par ) {
			$agendados[] = substr( $par, (int) strpos( $par, ' · ' ) + strlen( ' · ' ) );
		}

		foreach ( OwnCrons::RETIRED as $hook => $version ) {
			self::assertNotContains( $hook, $agendados, "«{$hook}» está en RETIRED y el plugin lo sigue agendando: va en RECURRING, SINGLE o QUEUE." );
			self::assertNotContains( $hook, $vivos, "«{$hook}» está a la vez entre los vivos y entre los retirados." );
			self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', (string) $version, "«{$hook}»: RETIRED anota la versión desde la que dejó de agendarse, que es lo que permite saber cuándo se puede soltar." );
		}

		// La tarjeta sólo mira RECURRING: un retirado no genera el aviso «cron
		// desagendado», que sería mentira —TIENE que estar desagendado—.
		self::assertSame( array(), array_intersect_key( OwnCrons::RETIRED, OwnCrons::RECURRING ) );
	}

	/**
	 * Hoy RETIRED está vacía —medido: ningún hook dejó de agendarse en los 207
	 * paquetes publicados—, así que comprobar el barrido contra la constante real
	 * sería una prueba que pasa sola. Se comprueba contra una COPIA del fichero
	 * con un retirado puesto, en un proceso aparte (una clase `final` con
	 * constantes no admite doble). Es el mismo fichero: si `unschedule_all()`
	 * deja de leer RETIRED, esto cae.
	 *
	 * @testdox Un hook retirado se sigue barriendo de WP-Cron y de Action Scheduler.
	 */
	public function test_un_hook_retirado_se_sigue_barriendo_de_las_dos_colas(): void {
		$fuente = (string) file_get_contents( $this->raiz() . '/src/Utilities/OwnCrons.php' );
		$copia  = preg_replace(
			'/public const RETIRED = array\(/',
			"public const RETIRED = array( 'akb_qa_modulo_borrado' => '9.9.9', ",
			$fuente,
			-1,
			$cambios
		);
		self::assertSame( 1, $cambios, 'No encuentro `public const RETIRED = array(` para sembrar el retirado de prueba.' );

		$temporal = tempnam( sys_get_temp_dir(), 'akb-owncrons-' );
		self::assertIsString( $temporal );
		file_put_contents( $temporal, (string) $copia ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$salida = $this->retirar_en_proceso_aparte( $temporal, true );
		} finally {
			unlink( $temporal ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		self::assertContains( 'akb_qa_modulo_borrado', $salida['cron'], 'El retirado no se barre de WP-Cron.' );
		self::assertContains( array( 'akb_qa_modulo_borrado', array(), '' ), $salida['cola'], 'El retirado no se barre de Action Scheduler.' );
	}

	/**
	 * Es lo que decide qué VIGILA la tarjeta: un cron recurrente nuevo que cayera
	 * en `SINGLE` se retiraría bien y no lo vigilaría nadie, que es exactamente
	 * como la tarjeta llegó a mirar 3 de 8.
	 *
	 * @testdox Los recurrentes de la lista son exactamente los que se agendan con recurrencia.
	 */
	public function test_los_recurrentes_son_los_que_se_agendan_con_recurrencia(): void {
		$recurrentes = array_keys( OwnCrons::RECURRING );
		sort( $recurrentes );

		self::assertSame( $this->hooks_agendados( true ), $recurrentes );

		foreach ( OwnCrons::RECURRING as $hook => $nombre ) {
			self::assertNotSame( '', trim( $nombre ), "«{$hook}» no tiene nombre para el dueño." );
		}
	}

	/**
	 * La otra mitad de S-0e169ea5: la cola de Action Scheduler, que es por donde
	 * va casi todo el trabajo del plugin, se comparaba con NADA. La copia de la
	 * desactivación retiraba 3 grupos de 7 y ninguna de las dos superficies
	 * retiraba la purga de Cloudflare.
	 *
	 * @testdox La cola única nombra cada grupo y cada hook que el plugin agenda en Action Scheduler, y ninguno más.
	 */
	public function test_la_cola_unica_es_exactamente_lo_que_se_agenda_en_action_scheduler(): void {
		$agendada = $this->cola_agendada();
		self::assertContains( 'akibara-bis · akb_bis_notify_product', $agendada, 'Si el escáner no ve ni la lista de espera, este candado no mide nada.' );

		$declarada = $this->cola_declarada();

		self::assertSame(
			array(),
			array_values( array_diff( $agendada, $declarada ) ),
			'Hay acciones que el plugin agenda en Action Scheduler y `OwnCrons::QUEUE` no nombra: siguen en la cola tras desactivar o desinstalar, fallando contra un hook sin callback.'
		);
		self::assertSame(
			array(),
			array_values( array_diff( $declarada, $agendada ) ),
			'`OwnCrons::QUEUE` nombra un grupo o un hook que ya nadie agenda. Si el módulo se borró, el hook se MUDA a `OwnCrons::RETIRED` con la versión; el grupo, si queda vacío, se quita.'
		);
		self::assertSame( count( $declarada ), count( array_unique( $declarada ) ), 'Un par grupo · hook repetido en QUEUE.' );
	}

	/**
	 * Retirar un grupo ENTERO sólo es seguro si el grupo es de este plugin y de
	 * nadie más: `akibara-business` en boca de un hermano haría que desactivar
	 * Business le cancelara su trabajo al otro.
	 *
	 * @testdox Ningún otro plugin ni mu-plugin propio agenda en los grupos que este plugin retira enteros.
	 */
	public function test_los_grupos_de_la_cola_son_solo_de_este_plugin(): void {
		$contenido = dirname( $this->raiz(), 2 );
		$grupos    = implode( '|', array_map( static fn( string $grupo ): string => preg_quote( $grupo, '/' ), array_keys( OwnCrons::QUEUE ) ) );
		$ajenos    = array_merge(
			(array) glob( $contenido . '/mu-plugins/akibara-*.php' ),
			$this->php_de( $contenido . '/plugins/akibara-search' ),
			$this->php_de( $contenido . '/plugins/akibara-rewards' ),
			$this->php_de( $contenido . '/plugins/akibara-cart-checkout-blocks' )
		);
		if ( array() === $ajenos ) {
			self::markTestSkipped( 'Sin plugins hermanos al lado (paquete suelto): no hay con quién chocar.' );
		}

		$intrusos = array();
		foreach ( $ajenos as $ruta ) {
			$codigo = $this->codigo_sin_comentarios( (string) $ruta );
			if ( preg_match( '/\bas_(?:schedule|enqueue|unschedule)[a-z_]*\s*\([^;]*\'(' . $grupos . ')\'/', $codigo, $m ) ) {
				$intrusos[] = substr( (string) $ruta, strlen( $contenido ) + 1 ) . " usa el grupo «{$m[1]}»";
			}
		}

		self::assertSame( array(), $intrusos );
	}

	/**
	 * @return array<int, string> Los `.php` propios de un plugin hermano (sin `vendor/` ni `tests/`).
	 */
	private function php_de( string $directorio ): array {
		if ( ! is_dir( $directorio ) ) {
			return array();
		}

		$rutas = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directorio, \FilesystemIterator::SKIP_DOTS ) ) as $fichero ) {
			$ruta = $fichero->getPathname();
			if ( 'php' === $fichero->getExtension() && ! preg_match( '#/(?:vendor|tests|node_modules)/#', $ruta ) ) {
				$rutas[] = $ruta;
			}
		}

		return $rutas;
	}

	/** @testdox Retirar usa wp_unschedule_hook para TODOS: es la única que ve los eventos con argumento. */
	public function test_retirar_usa_wp_unschedule_hook_para_todos(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		OwnCrons::unschedule_all();

		self::assertSame( OwnCrons::hooks(), $this->retirados );
		self::assertSame(
			array_merge( array_keys( OwnCrons::RECURRING ), OwnCrons::SINGLE, array_keys( OwnCrons::RETIRED ) ),
			OwnCrons::hooks(),
			'Lo que se retira de WP-Cron son los vivos MÁS los retirados.'
		);
		foreach ( array( 'akibara_business_social_image_for_slug', 'akibara_business_cleanup_collection_owner_fence' ) as $con_argumento ) {
			self::assertContains( $con_argumento, $this->retirados, "«{$con_argumento}» lleva argumentos y `wp_clear_scheduled_hook()` no lo ve." );
		}
	}

	/**
	 * El escenario del hallazgo: el dueño lanza una recompresión de portadas, o
	 * una purga deja pendiente el calentamiento de caché, y desactiva el plugin.
	 * La desinstalación se los llevaba; la desactivación, no.
	 *
	 * @testdox Retirar vacía también la cola de Action Scheduler: cada grupo entero y cada hook por su nombre.
	 */
	public function test_retirar_vacia_tambien_la_cola_de_action_scheduler(): void {
		OwnCrons::unschedule_all();

		foreach ( OwnCrons::QUEUE as $grupo => $hooks ) {
			self::assertContains( array( '', array(), $grupo ), $this->cola, "El grupo «{$grupo}» no se retira entero." );
			foreach ( $hooks as $hook ) {
				self::assertContains( array( $hook, array(), '' ), $this->cola, "«{$hook}» no se retira por su nombre: lo agendado bajo otro grupo, o sin grupo, se queda." );
			}
		}

		// Lo que la copia a mano de la desactivación se dejaba, por su nombre.
		foreach ( array( 'akb-image-recompress', 'akb-cache-warm', 'akibara-business' ) as $grupo ) {
			self::assertContains( array( '', array(), $grupo ), $this->cola, "Desactivar dejaba vivo el grupo «{$grupo}»." );
		}
		foreach ( array( 'akibara_business_image_recompress', 'akibara_business_image_recompress_purge', 'akibara_business_cache_warm', 'akibara_edge_purge_run' ) as $hook ) {
			self::assertContains( array( $hook, array(), '' ), $this->cola, "Desactivar dejaba vivo «{$hook}»." );
		}

		// `as_unschedule_all_actions( '', array(), '' )` no es «nada»: sin hook ni
		// grupo, Action Scheduler cancela lo que encuentre, sea de quien sea.
		foreach ( $this->cola as $llamada ) {
			self::assertNotSame( '', $llamada[0] . $llamada[2], 'Una retirada sin hook NI grupo se lleva acciones ajenas.' );
		}
	}

	/**
	 * La desactivación corre sin autoloader si WooCommerce está inactivo, y
	 * `uninstall.php` sin el plugin cargado. Se prueba en un proceso APARTE
	 * porque dentro de la suite el autoloader de Composer resolvería cualquier
	 * dependencia y la prueba pasaría sola —y porque aquí dentro no hay forma de
	 * que Action Scheduler «no exista»: Brain Monkey ya definió la función—.
	 *
	 * @testdox La lista se carga sola, sin autoloader ni ninguna otra clase del plugin, con Action Scheduler y sin él.
	 */
	public function test_la_lista_se_carga_sin_autoloader(): void {
		$fichero = $this->raiz() . '/src/Utilities/OwnCrons.php';

		$con_cola = $this->retirar_en_proceso_aparte( $fichero, true );
		self::assertSame( OwnCrons::hooks(), $con_cola['cron'], 'Cargada a solas, la lista tiene que retirar lo mismo de WP-Cron.' );
		self::assertCount( count( OwnCrons::QUEUE ) + count( $this->cola_declarada() ) + count( OwnCrons::RETIRED ), $con_cola['cola'], 'Cargada a solas, tiene que retirar la cola entera.' );

		// WooCommerce inactivo: no hay Action Scheduler. Ni fatal, ni se deja
		// WP-Cron a medias por haber preguntado por la cola.
		$sin_cola = $this->retirar_en_proceso_aparte( $fichero, false );
		self::assertSame( OwnCrons::hooks(), $sin_cola['cron'] );
		self::assertSame( array(), $sin_cola['cola'] );
	}

	/**
	 * Carga un `OwnCrons.php` a solas y retira todo, en otro proceso.
	 *
	 * @param string $fichero            Ruta del fichero a cargar.
	 * @param bool   $con_action_scheduler Si `as_unschedule_all_actions()` existe en ese proceso.
	 * @return array{cron: array<int, string>, cola: array<int, array<int, mixed>>}
	 */
	private function retirar_en_proceso_aparte( string $fichero, bool $con_action_scheduler ): array {
		$guion = 'define( "ABSPATH", "/" );'
			. '$GLOBALS["cron"] = array(); $GLOBALS["cola"] = array();'
			. 'function wp_unschedule_hook( $hook ) { $GLOBALS["cron"][] = $hook; return 0; }'
			. ( $con_action_scheduler ? 'function as_unschedule_all_actions( $hook, $args = array(), $group = "" ) { $GLOBALS["cola"][] = array( $hook, $args, $group ); }' : '' )
			. 'require $argv[1];'
			. '\Akibara\Business\Utilities\OwnCrons::unschedule_all();'
			. 'echo json_encode( array( "cron" => $GLOBALS["cron"], "cola" => $GLOBALS["cola"] ) );';

		$salida = shell_exec(
			escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $guion ) . ' -- ' . escapeshellarg( $fichero ) . ' 2>&1'
		);
		$leido  = json_decode( (string) $salida, true );

		self::assertIsArray( $leido, 'El proceso aparte no devolvió JSON. Salida: ' . (string) $salida );

		return $leido;
	}

	/**
	 * Candado de FUENTE, y a propósito: el cierre de la desactivación vive en el
	 * cargador del plugin y `uninstall.php` borra tablas al incluirse, así que
	 * ejecutarlos aquí exigiría montar WordPress entero. Lo que se fija es el
	 * cableado: las dos superficies pasan por la lista única y ninguna conserva
	 * una copia a mano al lado —ni de WP-Cron ni de Action Scheduler: la primera
	 * versión de este candado sólo miraba WP-Cron, y la copia de la cola siguió
	 * divergiendo delante de él (S-0e169ea5, 2026-09-19)—.
	 *
	 * @testdox La desactivación y la desinstalación retiran la lista única, sin copia propia de crons ni de cola.
	 */
	public function test_desactivacion_y_desinstalacion_pasan_por_la_lista_unica(): void {
		$cargador = $this->codigo_sin_comentarios( 'akibara-business.php' );
		$desde    = strpos( $cargador, 'register_deactivation_hook' );
		self::assertIsInt( $desde, 'Sin hook de desactivación, TODOS quedan huérfanos.' );

		$superficies = array(
			'akibara-business.php (desactivación)' => substr( $cargador, $desde ),
			'uninstall.php'                        => $this->codigo_sin_comentarios( 'uninstall.php' ),
		);

		foreach ( $superficies as $nombre => $codigo ) {
			self::assertMatchesRegularExpression(
				'/require_once\s+[^;]*src\/Utilities\/OwnCrons\.php\'\s*;/',
				$codigo,
				"{$nombre}: la lista se carga a mano; ahí no hay autoloader."
			);
			self::assertStringContainsString( '\Akibara\Business\Utilities\OwnCrons::unschedule_all();', $codigo, "{$nombre} no retira la lista única." );
			self::assertDoesNotMatchRegularExpression(
				'/\b(?:wp_(?:clear_scheduled_hook|unschedule_hook|unschedule_event)|as_unschedule_(?:all_actions|action))\s*\(/',
				$codigo,
				"{$nombre} vuelve a llevar su propia copia de la lista: es como divergieron la primera vez."
			);
		}
	}
}
