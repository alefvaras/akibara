<?php
/**
 * Contrato del panel de autocomplete bajo contexto de archive.
 *
 * Hasta 0.1.18 el panel y su pie contaban universos distintos: `total` cruzaba
 * los filtros del archive (`_serie=<slug>` en /serie/<slug>/) y las tarjetas
 * no, así que en /serie/jujutsu-kaisen/ teclear «tomo» devolvía productos de
 * otra serie bajo un «Ver los 30 resultados» que sí era de la serie. Enter y
 * clic llevaban a la página filtrada: el panel era la única superficie que
 * mentía [QA 2026-08-16].
 *
 * Acá se fijan las tres invariantes del arreglo, leídas de la fuente por el
 * mismo motivo que el resto de los contratos estructurales del plugin: montar
 * `handle_suggest()` exige el motor, el presenter, el servicio de facetas, el
 * receipt de publicación y el rate-limit a la vez, y un test de integración de
 * ese tamaño falla por razones ajenas a lo que quiere proteger.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @since 0.1.19
 */
final class SuggestScopeContractTest extends TestCase {

	/**
	 * Fuente del controlador.
	 */
	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/src/SuggestController.php' );
	}

	/**
	 * Cuerpo de `handle_suggest()`, que es donde viven las tres invariantes.
	 */
	private function handle_suggest_body(): string {
		$source = $this->source();
		$start  = strpos( $source, 'public function handle_suggest' );
		self::assertNotFalse( $start, 'handle_suggest() debe existir.' );
		$end = strpos( $source, "\n\tprivate function", (int) $start );
		if ( false === $end ) {
			$end = strlen( $source );
		}

		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * @testdox Las tarjetas y el total se filtran con el MISMO FilterSet, leído una sola vez.
	 */
	public function test_items_and_total_share_one_filter_set(): void {
		$body = $this->handle_suggest_body();

		// Una sola foto de la query: dos lecturas podrían divergir si algo
		// tocara los params en vuelo, y era justo la divergencia que se
		// arregló. `FacetQueryIntegration` fija el mismo contrato aguas abajo.
		self::assertSame(
			1,
			substr_count( $body, 'get_query_params()' ),
			'Los parámetros de la query deben leerse UNA vez y reusarse; dos lecturas reabren la divergencia entre el panel y su pie.'
		);
		self::assertSame(
			1,
			substr_count( $body, 'filters_from_query_vars(' ),
			'El FilterSet debe derivarse UNA vez y compartirse entre las tarjetas y el total.'
		);

		// Y esa foto tiene que tomarse ANTES de buscar: es lo que decide el
		// tamaño del barrido.
		$pos_filters = strpos( $body, 'filters_from_query_vars(' );
		$pos_suggest = strpos( $body, '$this->engine->suggest(' );
		self::assertNotFalse( $pos_filters );
		self::assertNotFalse( $pos_suggest );
		self::assertLessThan(
			$pos_suggest,
			$pos_filters,
			'Los filtros deben resolverse antes de buscar: condicionan cuántos ids se piden.'
		);
	}

	/**
	 * @testdox El filtrado por faceta conserva el orden de ranking del motor.
	 */
	public function test_scoped_filter_preserves_engine_ranking(): void {
		$body = $this->handle_suggest_body();

		// `FacetService::filter()` documenta su retorno como «post_ids (sin
		// ordenar)» y su SELECT no lleva ORDER BY. Quedarse con esa salida y
		// recortarla daría 7 tomos en orden de tabla en vez de los 7 mejor
		// rankeados: el panel abriría por un tomo cualquiera.
		self::assertMatchesRegularExpression(
			'/\$allowed\s*=\s*\$this->facets->filter\(/',
			$body,
			'El retorno de facets->filter() debe usarse como conjunto de permitidos, no como la lista final.'
		);
		self::assertDoesNotMatchRegularExpression(
			'/\$ids\s*=\s*\$this->facets->filter\(/',
			$body,
			'Asignar el filtro directo a $ids pierde el orden del motor: FacetService no ordena.'
		);
		self::assertStringContainsString(
			'array_flip( $allowed )',
			$body,
			'El conjunto de permitidos debe consultarse por clave para recorrer $ids en el orden del motor.'
		);

		// El recorte tiene que ocurrir DESPUÉS de reordenar contra $ids.
		$pos_flip  = strpos( $body, 'array_flip( $allowed )' );
		$pos_slice = strpos( $body, 'array_slice( $ids, 0, self::SUGGEST_FETCH )' );
		self::assertNotFalse( $pos_flip );
		self::assertNotFalse( $pos_slice );
		self::assertLessThan( $pos_slice, $pos_flip, 'Primero se reordena contra $ids, después se recorta.' );
	}

	/**
	 * @testdox El filtro del panel es fail-closed y el barrido bajo contexto está acotado.
	 */
	public function test_scoped_path_is_fail_closed_and_bounded(): void {
		$body = $this->handle_suggest_body();

		// Toda lectura autoritativa del plugin responde 503 antes que servir un
		// resultado a medias; el filtro nuevo es una lectura más.
		self::assertMatchesRegularExpression(
			'/\$allowed\s*=\s*\$this->facets->filter\([^;]+;\s*if\s*\(\s*!\s*\$this->facets->is_available\(\)\s*\)\s*\{\s*return \$this->suggest_unavailable/s',
			$body,
			'Tras filtrar hay que comprobar is_available() y cerrar: sin eso, una lectura caída se sirve como «no hay resultados».'
		);

		// Bajo contexto se pide el ranking COMPLETO. Un techo intermedio recorta
		// el ranking GLOBAL antes de conocer la faceta, así que con una consulta
		// ancha los mejores globales casi no contienen productos de la serie del
		// archive: /serie/chainsaw-man/ devolvía total=0 con 23 productos en la
		// página destino [contraste 2026-08-17]. Y no cuesta consultas: el motor
		// no lleva LIMIT en el SELECT, recorta en PHP después de puntuar.
		self::assertStringContainsString(
			'$scoped ? QueryEngine::MAX_RESULTS',
			$body,
			'Bajo contexto hay que pedir el ranking completo: cualquier techo recorta antes de filtrar por faceta.'
		);
		self::assertStringNotContainsString(
			'SCOPED_FETCH',
			$this->source(),
			'El techo intermedio se retiró a propósito; reintroducirlo devuelve el panel vacío en consultas anchas.'
		);
	}

	/**
	 * @testdox La señal de truncamiento se recalcula tras filtrar.
	 */
	public function test_truncation_signal_is_recomputed_after_filtering(): void {
		$body = $this->handle_suggest_body();

		// El gate que decide recalcular `total` miraba `SUGGEST_FETCH ===
		// count($ids)`. Bajo contexto los ids ya vienen filtrados y recortados,
		// así que esa comparación dejaría de significar «pudo truncar».
		self::assertStringContainsString(
			'|| $truncated )',
			$body,
			'El gate de total debe usar la señal $truncated, no comparar contra el tope del over-fetch.'
		);
		// La señal se COMBINA con la del motor, no la sustituye: al sobreescribirla,
		// un set filtrado corto dejaba `$truncated` en false, el gate no disparaba
		// y el pie se quedaba con `count($items)` — el footer volvía a prometer un
		// número que la página destino contradice [contraste 2026-08-17].
		self::assertMatchesRegularExpression(
			'/\$truncated\s*=\s*\$truncated \|\| count\( \$ids \) > self::SUGGEST_FETCH;/',
			$body,
			'Bajo contexto la señal debe combinarse con la del motor, no reemplazarla.'
		);
	}
	/**
	 * El footer no puede contradecir a las tarjetas. Con la editorial soltada,
	 * el recuento se hace sobre lo que de verdad se buscó: contar la consulta
	 * cruda («jujutsu kaisen ivrea») da cero, y el panel mostraba tarjetas bajo
	 * un «Ver los 0 resultados» [laboratorio 2026-09-05].
	 *
	 * @testdox El total se cuenta sobre la búsqueda relajada, no sobre la editorial que se soltó.
	 */
	public function test_the_total_counts_the_relaxed_query(): void {
		$body = $this->handle_suggest_body();

		self::assertStringContainsString( '$relaxed   = $result[\'relaxed\'] ?? null;', $body, 'El controlador tiene que recibir lo soltado.' );
		self::assertMatchesRegularExpression(
			'/\$total_query = \$corrected\[.fix.\] \?\? \( is_array\( \$relaxed \) \? \(string\) \$relaxed\[.kept.\] : \$query \);/',
			$body,
			'El recuento va sobre lo buscado de verdad.'
		);
	}

	/**
	 * @testdox El aviso de editorial no viaja sobre un panel vacío, igual que la corrección.
	 */
	public function test_the_relaxed_notice_never_travels_with_an_empty_panel(): void {
		$body = $this->handle_suggest_body();

		$vacio = strpos( $body, 'if ( empty( $items ) ) {' );
		self::assertNotFalse( $vacio );
		$bloque = substr( $body, (int) $vacio, 300 );
		self::assertStringContainsString( '$corrected = null;', $bloque );
		self::assertStringContainsString( '$relaxed = null;', $bloque, 'Sin tarjetas, «esto es lo que tenemos» no señala nada.' );
	}

}
