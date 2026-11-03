<?php
/**
 * El protocolo fail-closed, ahora en un solo sitio.
 *
 * Estaba copiado en SEIS clases con cuatro formas distintas de leer
 * `last_error` del `$wpdb`. Este test fija las tres propiedades que hacen que
 * cerrar sirva de algo, y que antes había que verificar clase por clase:
 *
 *   1. gana el PRIMER diagnóstico — el segundo fallo suele ser consecuencia del
 *      primero, y pisarlo borra la causa;
 *   2. el error REAL de la base le gana al mensaje genérico del caller;
 *   3. cerrar es IRREVERSIBLE dentro del request.
 *
 * Y una cuarta que no es del protocolo pero se rompe igual de silenciosamente:
 * al cerrar hay que soltar los memos derivados, o una lectura ya no autoritativa
 * sobrevive en memoria y se sirve como si lo fuera.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetService;
use Akibara\Search\PopularQueries;
use Akibara\Search\ProductPresenter;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\QueryEngine;
use Akibara\Search\SeriesSuggestions;
use Akibara\Search\Support\FailsClosed;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Consumidor mínimo del trait, con una conexión que sí reporta errores. */
final class FailsClosedProbe {

	use FailsClosed;

	/** @var object|null */
	private $db;

	public int $forgotten = 0;

	/** @param object|null $db Conexión. */
	public function __construct( $db = null ) {
		$this->db = $db;
	}

	public function close( string $fallback ): void {
		$this->mark_unavailable( $fallback );
	}

	private function forget_derived_state(): void {
		++$this->forgotten;
	}
}

final class FailsClosedProtocolTest extends TestCase {

	/** @testdox Gana el primer diagnóstico y el cierre es irreversible. */
	public function test_gana_el_primer_diagnostico_y_el_cierre_es_irreversible(): void {
		$probe = new FailsClosedProbe();

		self::assertTrue( $probe->is_available() );
		self::assertSame( '', $probe->last_error() );

		$probe->close( 'la causa' );
		$probe->close( 'la consecuencia' );

		self::assertFalse( $probe->is_available() );
		self::assertSame( 'la causa', $probe->last_error(), 'Pisar el diagnóstico borra la causa.' );
		self::assertSame( 2, $probe->forgotten, 'Cada cierre suelta el estado derivado.' );
	}

	/** @testdox El error real de la base le gana al mensaje genérico del caller. */
	public function test_el_error_de_la_base_le_gana_al_generico(): void {
		$db             = new class() {
			public string $last_error = "  Table 'wp_akb_search_docs' doesn't exist  ";
		};
		$probe          = new FailsClosedProbe( $db );

		$probe->close( 'Could not read facet counts.' );

		self::assertSame( "Table 'wp_akb_search_docs' doesn't exist", $probe->last_error() );
	}

	/** @testdox Sin conexión, o sin error legible, se conserva el mensaje del caller. */
	public function test_sin_error_legible_queda_el_mensaje_del_caller(): void {
		$sin_db = new FailsClosedProbe();
		$sin_db->close( 'Could not read the authoritative snapshot.' );
		self::assertSame( 'Could not read the authoritative snapshot.', $sin_db->last_error() );

		$sin_texto = new FailsClosedProbe(
			new class() {
				/** @var mixed */
				public $last_error = null;
			}
		);
		$sin_texto->close( 'Could not read the authoritative snapshot.' );
		self::assertSame( 'Could not read the authoritative snapshot.', $sin_texto->last_error() );
	}

	/**
	 * @testdox Las seis clases de lectura comparten la MISMA implementación.
	 *
	 * Es la invariante del hallazgo: seis copias del protocolo eran seis
	 * oportunidades de que una divergiera sin que nadie lo notara.
	 */
	public function test_las_seis_clases_comparten_la_implementacion(): void {
		$clases = array(
			FacetService::class,
			QueryEngine::class,
			ProductPresenter::class,
			ProductSourceAuthority::class,
			PopularQueries::class,
			SeriesSuggestions::class,
		);

		// Estas tres memoizan lecturas que dejan de ser autoritativas al cerrar.
		$con_memo = array( FacetService::class, QueryEngine::class, SeriesSuggestions::class );

		foreach ( $clases as $clase ) {
			$reflection = new ReflectionClass( $clase );

			self::assertContains(
				'Akibara\\Search\\Support\\FailsClosed',
				$reflection->getTraitNames(),
				$clase . ' tiene que usar el protocolo compartido.'
			);

			// PHP aplana los traits, pero conserva el fichero de origen de cada
			// método: si una clase se escribe su propia copia, el fichero deja
			// de ser el del trait.
			foreach ( array( 'is_available', 'last_error', 'mark_unavailable', 'database_error' ) as $metodo ) {
				self::assertSame(
					'FailsClosed.php',
					basename( (string) ( new ReflectionMethod( $clase, $metodo ) )->getFileName() ),
					$clase . '::' . $metodo . '() no puede ser una copia propia.'
				);
			}

			// Y lo que sí es propio de cada clase —qué memo derivado invalidar—
			// sigue declarándose en la clase que tiene ese memo.
			$propio   = 'FailsClosed.php' !== basename( (string) ( new ReflectionMethod( $clase, 'forget_derived_state' ) )->getFileName() );
			$esperado = in_array( $clase, $con_memo, true );

			self::assertSame(
				$esperado,
				$propio,
				$esperado
					? $clase . ' tiene memo derivado y DEBE soltarlo al cerrar.'
					: $clase . ' no tiene memo derivado: hereda el hook vacío, no una copia.'
			);
		}
	}
}
