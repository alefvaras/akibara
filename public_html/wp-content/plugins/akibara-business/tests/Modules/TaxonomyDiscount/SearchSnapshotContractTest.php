<?php
/**
 * Candado del contrato CRUZADO de la foto de precios Search↔Business.
 *
 * `EditorialDiscountPublication::options()` (akibara-search) y
 * `AdoptedSnapshot::OPTIONS` (este plugin) son el MISMO contrato: la foto que
 * search publica y business adopta. El 2026-09-01 entró una option nueva SOLO
 * por el lado de search y la adopción rechazó la foto entera: suggest/popular
 * en 503 y /tienda/?_buscar sirviendo el catálogo completo sin filtrar, en
 * silencio. Cada suite era verde porque probaba su lado con dobles — este test
 * lee LOS DOS árboles (mismo criterio inter-plugin que
 * OptionNamespaceBoundaryTest) para que el contrato no pueda volver a moverse
 * a medias.
 *
 * LA LISTA NO BASTA, y costó otra caída averiguarlo. `akb_discount_max_pct`
 * entró en la lista por los DOS lados, y el DOMINIO se escribió sólo en search:
 * el switch de `AdoptedSnapshot::valid_option_value()` no tenía su `case`, así
 * que la rechazaba con cualquier valor —incluido su propio default de 15— y en
 * cuanto la fila existiera la foto entera dejaba de adoptarse. Este candado
 * comparaba nombres y por eso siguió verde. Producción se salvó de milagro:
 * la fila no existe todavía, y las options ausentes se saltan sin validar, así
 * que el primer «Guardar» del campo del panel habría repetido el 503 de tres
 * horas del 2026-09-05.
 *
 * Por eso ahora se cruzan también los DOMINIOS, invocando los dos validadores
 * de verdad —el de business por reflexión, el de search en un proceso PHP
 * aparte que carga su fichero real— sobre los mismos valores. Mismo criterio
 * que la paridad PHP↔JS del validador de celular: duplicar una regla en dos
 * sitios es un coste aceptado sólo si la divergencia sale en rojo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use PHPUnit\Framework\TestCase;

final class SearchSnapshotContractTest extends TestCase {

	/**
	 * @testdox La lista de options de la foto es idéntica en search y en business (orden ksort incluido).
	 */
	public function test_both_sides_of_the_price_snapshot_contract_agree(): void {
		$search_file = dirname( __DIR__, 4 ) . '/akibara-search/src/EditorialDiscountPublication.php';
		if ( ! is_readable( $search_file ) ) {
			self::markTestSkipped( 'El plugin akibara-search no está en este árbol.' );
		}

		$reflection = new \ReflectionClassConstant( AdoptedSnapshot::class, 'OPTIONS' );
		/** @var array<int, string> $business_side */
		$business_side = $reflection->getValue();

		$search_side = $this->search_snapshot_options( (string) file_get_contents( $search_file ) );

		self::assertNotSame( array(), $search_side, 'No se pudo extraer options() del fuente de search: si cambió de forma, actualizar este extractor.' );

		$expected = $business_side;
		sort( $expected, SORT_STRING );
		self::assertSame(
			$expected,
			$business_side,
			'AdoptedSnapshot::OPTIONS debe estar en orden ksort: la foto llega ordenada por nombre.'
		);

		sort( $search_side, SORT_STRING );
		self::assertSame(
			$expected,
			$search_side,
			'El contrato de la foto se movió A MEDIAS: EditorialDiscountPublication::options() (search) y AdoptedSnapshot::OPTIONS (business) deben listar EXACTAMENTE las mismas options — mover una sin la otra tumba el buscador servido entero.'
		);
	}

	/**
	 * Un valor legítimo por option, escrito a mano. Si alguien añade una option
	 * al contrato y no le escribe candidato aquí, el test cae con su nombre.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function legitimate_values(): array {
		return array(
			'akb_discount_max_pct'                          => array( '1', '15', '90' ),
			'akibara_business_search_feed_contract_version' => array( '2' ),
			'akibara_taxonomy_discount_apply_to'            => array( 'instock_only', 'instock_and_onbackorder', 'all' ),
			'akibara_taxonomy_discount_enabled'             => array( 'yes', 'no' ),
			'akibara_taxonomy_discount_from'                => array( '2026-09-05' ),
			'akibara_taxonomy_discount_sale_policy'         => array( 'greatest_wins', 'from_regular', 'skip_if_on_sale' ),
			'akibara_taxonomy_discount_to'                  => array( '2026-09-07' ),
			'gmt_offset'                                    => array( '-4', '0' ),
			'timezone_string'                               => array( 'America/Santiago' ),
			'woocommerce_price_num_decimals'                => array( '0', '2' ),
		);
	}

	/**
	 * El candado que faltaba: los dos lados tienen que DECIDIR igual, no sólo
	 * listar igual.
	 *
	 * @testdox Cada option del contrato acepta al menos un valor legítimo, y search y business deciden lo mismo.
	 */
	public function test_both_sides_agree_on_every_domain(): void {
		$search_file = dirname( __DIR__, 4 ) . '/akibara-search/src/EditorialDiscountPublication.php';
		if ( ! is_readable( $search_file ) ) {
			self::markTestSkipped( 'El plugin akibara-search no está en este árbol.' );
		}

		$reflection = new \ReflectionClassConstant( AdoptedSnapshot::class, 'OPTIONS' );
		/** @var array<int, string> $contrato */
		$contrato   = $reflection->getValue();
		$candidatos = $this->legitimate_values();

		$business = new \ReflectionMethod( AdoptedSnapshot::class, 'valid_option_value' );
		$business->setAccessible( true );

		$pares = array();
		foreach ( $contrato as $option ) {
			self::assertArrayHasKey(
				$option,
				$candidatos,
				sprintf( '«%s» entró en el contrato sin candidato conocido: añádele su dominio en LOS DOS validadores y aquí.', $option )
			);
			foreach ( $candidatos[ $option ] as $valor ) {
				$pares[] = array( $option, $valor );
			}
		}

		$search_dice = $this->search_verdicts( $search_file, $pares );

		foreach ( $pares as $i => $par ) {
			list( $option, $valor ) = $par;
			$business_dice          = (bool) $business->invoke( null, $option, $valor );

			self::assertTrue(
				$business_dice,
				sprintf(
					'Business RECHAZA «%s» = «%s», un valor legítimo. En cuanto esa fila exista, la foto entera deja de adoptarse: capture_price_authority() null, los claims a blocked y el buscador en 503 con las facetas apagadas (incidente 2026-09-05).',
					$option,
					$valor
				)
			);
			self::assertSame(
				$search_dice[ $i ],
				$business_dice,
				sprintf(
					'Los dos lados del contrato deciden distinto sobre «%s» = «%s»: search dice %s y business %s. Una foto que uno publica y el otro rechaza es el buscador servido sin filtros.',
					$option,
					$valor,
					$search_dice[ $i ] ? 'válido' : 'inválido',
					$business_dice ? 'válido' : 'inválido'
				)
			);
		}
	}

	/**
	 * Options cuyos dos validadores SÍ divergen y da igual, porque la
	 * equivalencia la garantiza otro punto del sistema.
	 *
	 * Sólo `timezone_string`, y está razonado en `AdoptedSnapshot.php:324-334`:
	 * la función de search es más laxa (mira largo y NUL), pero el gate real de
	 * publicación no es esa función sino `effective_timezone()`, que pasa el
	 * literal a `new DateTimeZone` y devuelve null si lanza — y
	 * `source_snapshot_from_options()` exige esa zona para emitir la foto. Una
	 * zona que PHP no entiende hace que search NO PUBLIQUE, así que no existe la
	 * foto que uno publica y el otro rechaza, que es lo único que este candado
	 * persigue.
	 *
	 * Esa equivalencia la fija `PriceSnapshotTimezoneCrossRailTest` (10 pruebas).
	 * **Si se toca una de las dos copias, se toca esa prueba.** Excluirla aquí no
	 * es taparla: es no medirla dos veces y mal, porque comparando función contra
	 * función salían 592 divergencias que el sistema no puede producir.
	 *
	 * @var array<int, string>
	 */
	private const DOMINIOS_EQUIVALENTES_POR_OTRA_VIA = array( 'timezone_string' );

	/**
	 * Todos los literales que aparecen en LOS DOS validadores, más basura.
	 *
	 * Es el universo contra el que se cruzan los dos lados. Sale del FUENTE y no
	 * de una lista escrita a mano justamente porque una lista a mano no puede
	 * cubrir un valor que alguien añada mañana: si alguien mete
	 * `instock_and_preventa` en el validador de business, ese literal entra aquí
	 * solo, se prueba contra search —que lo rechaza— y el test cae.
	 *
	 * @param string $search_file Fuente de search.
	 * @return array<int, string>
	 */
	private function every_literal_in_play( string $search_file ): array {
		$business_file = dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/AdoptedSnapshot.php';
		$universo      = array();

		foreach ( array( $search_file, $business_file ) as $fichero ) {
			if ( ! is_readable( $fichero ) ) {
				continue;
			}
			preg_match_all( "/'([A-Za-z0-9_\\-\\.\\/+:]{1,40})'/", (string) file_get_contents( $fichero ), $m );
			foreach ( $m[1] as $literal ) {
				$universo[ $literal ] = true;
			}
		}

		// Basura que ninguno de los dos puede aceptar jamás.
		foreach ( array( '', ' ', 'SI', 'yes ', '  all', 'INSTOCK_ONLY', 'null', '0x10', 'instock_and_preventa' ) as $basura ) {
			$universo[ $basura ] = true;
		}

		return array_keys( $universo );
	}

	/**
	 * EL CANDADO QUE FALTABA: los dos lados tienen que RECHAZAR lo mismo.
	 *
	 * El test de arriba sólo preguntaba «¿los dos aceptan esto?», así que
	 * ENSANCHAR el dominio por un lado era invisible — y ésa es exactamente la
	 * forma del incidente del 2026-09-01 que este fichero dice prevenir. QA lo
	 * midió con control el 2026-09-05: añadir un valor de más sólo en business, o
	 * sólo en search, dejaba la suite VERDE.
	 *
	 * Peor todavía: se podía BORRAR `instock_and_onbackorder` entero del
	 * validador de business y las 2.750 pruebas seguían verdes, porque ese valor
	 * ni siquiera estaba entre los candidatos. Es el incidente del 2026-09-05 con
	 * otro nombre: un dominio que un lado acepta y el otro no, la foto rechazada
	 * entera, el buscador en 503 y las facetas apagadas.
	 *
	 * @testdox Los dos lados aceptan y RECHAZAN exactamente lo mismo, valor por valor.
	 */
	public function test_both_sides_reject_exactly_the_same_things(): void {
		$search_file = dirname( __DIR__, 4 ) . '/akibara-search/src/EditorialDiscountPublication.php';
		if ( ! is_readable( $search_file ) ) {
			self::markTestSkipped( 'El plugin akibara-search no está en este árbol.' );
		}

		$reflection = new \ReflectionClassConstant( AdoptedSnapshot::class, 'OPTIONS' );
		/** @var array<int, string> $contrato */
		$contrato = $reflection->getValue();
		$universo = $this->every_literal_in_play( $search_file );

		$pares = array();
		foreach ( $contrato as $option ) {
			if ( in_array( $option, self::DOMINIOS_EQUIVALENTES_POR_OTRA_VIA, true ) ) {
				continue;
			}
			foreach ( $universo as $valor ) {
				$pares[] = array( $option, $valor );
			}
		}

		$business = new \ReflectionMethod( AdoptedSnapshot::class, 'valid_option_value' );
		$business->setAccessible( true );
		$search_dice = $this->search_verdicts( $search_file, $pares );

		$divergencias = array();
		foreach ( $pares as $i => $par ) {
			list( $option, $valor ) = $par;
			$b                      = (bool) $business->invoke( null, $option, $valor );
			if ( $b !== $search_dice[ $i ] ) {
				$divergencias[] = sprintf(
					'%s = «%s» (search: %s, business: %s)',
					$option,
					$valor,
					$search_dice[ $i ] ? 'válido' : 'inválido',
					$b ? 'válido' : 'inválido'
				);
			}
		}

		self::assertSame(
			array(),
			$divergencias,
			"Los dos lados del contrato deciden distinto sobre estos valores. Una foto que uno publica y el otro rechaza deja el buscador servido SIN filtros y el catálogo entero sin buscar:\n  - " . implode( "\n  - ", $divergencias )
		);
	}

	/**
	 * Invoca el validador REAL de search en un proceso aparte.
	 *
	 * Se ejecuta fuera para no arrastrar su clase a esta suite, igual que el
	 * extractor de abajo lee el fuente en vez de cargarlo: lo que se cruza son
	 * los dos runtimes de verdad, no una copia del dominio escrita aquí.
	 *
	 * @param string                        $search_file Ruta del fichero de search.
	 * @param array<int, array{0:string,1:string}> $pares  Pares option/valor.
	 * @return array<int, bool>
	 */
	private function search_verdicts( string $search_file, array $pares ): array {
		$codigo = 'define("ABSPATH","/");'
			. 'require ' . var_export( $search_file, true ) . ';'
			. '$m = new ReflectionMethod("Akibara\\Search\\EditorialDiscountPublication","valid_source_option_value");'
			. '$m->setAccessible(true);'
			. '$pares = json_decode(stream_get_contents(STDIN), true);'
			. '$out = [];'
			. 'foreach ($pares as $p) { $out[] = (bool) $m->invoke(null, $p[0], $p[1]); }'
			. 'echo json_encode($out);';

		// Los pares viajan por la ENTRADA ESTÁNDAR, no dentro de `-r`: en Linux un solo
		// argumento no puede pasar de ~128 KB (MAX_ARG_STRLEN) y el JSON de todos los
		// pares ya lo supera. En macOS el tope es mayor, así que en local pasaba y en el
		// gate de Docker fallaba con «Argument list too long» (2026-09-14).
		$descriptores = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// Entorno mínimo a propósito: heredar el del proceso padre hacía que
		// `posix_spawn()` fallara con «Argument list too long» en sesiones cuyo
		// entorno viene cargado (medido el 2026-09-11: el mismo test en verde
		// con `env -i`). El hijo sólo necesita PATH para arrancar PHP.
		$entorno = array(
			'PATH' => (string) getenv( 'PATH' ),
			'HOME' => (string) getenv( 'HOME' ),
		);
		$proceso = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $codigo ), $descriptores, $tuberias, null, $entorno );

		self::assertIsResource( $proceso, 'No se pudo lanzar PHP: sin él no se puede cruzar el dominio de los dos lados.' );

		fwrite( $tuberias[0], (string) wp_json_encode( $pares ) );
		fclose( $tuberias[0] );

		$salida = (string) stream_get_contents( $tuberias[1] );
		$error  = (string) stream_get_contents( $tuberias[2] );
		fclose( $tuberias[1] );
		fclose( $tuberias[2] );
		$codigo_salida = proc_close( $proceso );

		self::assertSame( 0, $codigo_salida, 'El validador de search no se pudo ejecutar: ' . $error );

		$veredictos = json_decode( $salida, true );
		self::assertIsArray( $veredictos, 'Salida inesperada del validador de search: ' . $salida . ' ' . $error );
		self::assertCount( count( $pares ), $veredictos );

		return array_map( 'boolval', $veredictos );
	}

	/**
	 * Extrae los nombres reales de `options()` del fuente de search: resuelve
	 * cada `self::OPTION_*` contra su declaración `private const`.
	 *
	 * @param string $source Fuente completo del fichero de search.
	 * @return array<int, string>
	 */
	private function search_snapshot_options( string $source ): array {
		if ( 1 !== preg_match( '/public static function options\(\): array \{\s*return array\((.*?)\);/s', $source, $body ) ) {
			return array();
		}

		preg_match_all( '/self::(OPTION_[A-Z_]+)/', $body[1], $refs );

		$constants = array();
		preg_match_all( "/const (OPTION_[A-Z_]+)\s*=\s*'([^']+)'/", $source, $decls, PREG_SET_ORDER );
		foreach ( $decls as $decl ) {
			$constants[ $decl[1] ] = $decl[2];
		}

		$names = array();
		foreach ( $refs[1] as $ref ) {
			if ( isset( $constants[ $ref ] ) ) {
				$names[] = $constants[ $ref ];
			}
		}

		return $names;
	}
}
