<?php
/**
 * FacetsView — view-model de las facetas para el request actual.
 *
 * Contrato de la mesa (§4.5): el plugin EXPONE estructuras, el theme IMPRIME.
 * Esta clase produce un array plano (facetas con counts, chips activos, total,
 * agrupación A-Z) que el theme renderiza como `<form method="get">` con
 * inputs — cero `<a>` facetados (crawlers no expanden checkboxes), cero
 * `$wpdb` en el theme (se corrige la violación vigente de inc/facets.php).
 *
 * Clase PURA (recibe el array de counts ya calculado por FacetService; no
 * toca WP ni SQL) — testeable directo, patrón Scorer/Normalizer.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class FacetsView {

	/**
	 * Orden canónico de las facetas (evidencia Baymard/NN-g: los filtros más
	 * usados arriba). Transaccionales primero (Disponibilidad/Precio/Oferta/
	 * Novedades — "¿qué llegó?" es LA pregunta de retorno del coleccionista,
	 * mesa D4), luego browse (Categoría/Demografía/Sello/Editorial/Género/
	 * País), y las grandes con buscador propio al final (Autor/Serie). El
	 * admin puede pisar este orden con la clave `order` del override.
	 *
	 * @var array<int, string>
	 */
	private const ORDER = array(
		'disponibilidad',
		'precio',
		'oferta',
		'novedad',
		'categoria',
		'demografia',
		'sello',
		'editorial',
		'genero',
		'pais',
		'autor',
		'serie',
	);

	/**
	 * Facetas EXPANDIDAS por defecto (el resto colapsa). Evidencia: no abrir
	 * todo (muro); dejar abiertas las de decisión primaria.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_DEFAULT = array( 'disponibilidad', 'precio', 'categoria' );

	/**
	 * Orden efectivo de render por faceta. Resuelve la precedencia entre el
	 * `order` explícito del override de administración y el orden canónico por defecto.
	 *
	 * Regla: el `order` explícito manda. Una faceta sin override explícito se
	 * intercala inmediatamente después de su predecesor canónico más cercano.
	 *
	 * @param array<string, array<string, mixed>> $defs Definiciones efectivas (FacetRegistry::definitions()).
	 * @return array<string, float> nombre → clave de orden sorteable.
	 */
	public static function effective_orders( array $defs ): array {
		$out = array();

		foreach ( $defs as $name => $def ) {
			$name = (string) $name;

			if ( isset( $def['order'] ) ) {
				$out[ $name ] = (float) (int) $def['order'];
				continue;
			}

			$canon_idx = array_search( $name, self::ORDER, true );
			if ( false === $canon_idx ) {
				$out[ $name ] = 9999.0;
				continue;
			}

			$pred = -1.0;
			for ( $i = $canon_idx - 1; $i >= 0; $i-- ) {
				$pred_def = $defs[ self::ORDER[ $i ] ] ?? null;
				if ( is_array( $pred_def ) && isset( $pred_def['order'] ) ) {
					$pred = (float) (int) $pred_def['order'];
					break;
				}
			}

			// Fracción < 1 (el canon tiene ~12 entradas): nunca alcanza al
			// siguiente entero explícito ni colisiona con otra faceta nueva.
			$out[ $name ] = $pred + ( $canon_idx + 1 ) / 1000.0;
		}

		return $out;
	}

	/**
	 * View-model completo a partir de los counts ya calculados.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $counts  Counts por faceta (FacetService::counts).
	 * @param FilterSet                                           $filters Selecciones activas.
	 * @param int                                                 $total   Total de resultados del set filtrado.
	 * @return array{total: int, facets: array<int, array<string, mixed>>, chips: array<int, array<string, string>>, has_filters: bool, search: string}
	 */
	public function build( array $counts, FilterSet $filters, int $total ): array {
		$defs   = FacetRegistry::definitions();
		$orders = self::effective_orders( $defs );

		$facets = array();
		$chips  = array();

		// Chip de la búsqueda activa (primera posición): permite confirmar y remover
		// el término buscado directamente desde la barra de chips en mobile y desktop.
		if ( '' !== $filters->search() ) {
			$chips[] = array(
				'facet'       => 'buscar',
				'facet_label' => 'Búsqueda',
				'value'       => $filters->search(),
				'label'       => '«' . $filters->search() . '»',
			);
		}

		foreach ( $defs as $name => $def ) {
			if ( isset( $def['visible'] ) && ! $def['visible'] ) {
				continue; // faceta ocultada desde el admin.
			}
			if ( (string) $name === $filters->context_facet() ) {
				// La faceta dueña del término del archive no se ofrece dentro de
				// su propio archive (en /manga/ ya estás en Manga; la subdivisión
				// la dan demografia/sello). Sus counts con el contexto en la base
				// solo devolverían el propio término — ruido, no filtro.
				continue;
			}

			$options = array_values( $counts[ $name ] ?? array() );

			// Ocultar opciones sin resultados (count 0) que el usuario no haya seleccionado.
			// Un bucket con count 0 que esté seleccionado se conserva para permitir deseleccionarlo.
			$options = array_values( array_filter(
				$options,
				static fn( $opt ): bool => ( $opt['count'] ?? 0 ) > 0 || ! empty( $opt['selected'] )
			) );

			// Oculta la faceta si no cuenta con opciones disponibles ni selecciones activas.
			if ( array() === $options ) {
				continue;
			}

			$ui = (string) ( $def['ui'] ?? 'checkboxes' );

			// Facetas con alto volumen de términos (autor/serie): ordenadas por cantidad
			// descendente para priorizar valores con mayor stock disponible en catálogo.

			$facets[] = array(
				'name'    => (string) $name,
				'label'   => (string) ( $def['label'] ?? $name ),
				'ui'      => $ui,
				'param'   => '_' . $name,
				'order'   => $orders[ (string) $name ],
				// Abierta por omisión según OPEN_DEFAULT o si cuenta con selección activa.
				'open'    => in_array( (string) $name, self::OPEN_DEFAULT, true ) || ! empty( $filters->values( (string) $name ) ),
				'options' => $options,
			);
		}

		// Chips: una por valor seleccionado, desde las selecciones del FilterSet
		// (independientemente de si la faceta se renderiza en la página actual),
		// garantizando que el usuario siempre pueda ver y remover cualquier filtro activo.
		foreach ( $defs as $name => $def ) {
			foreach ( $filters->values( (string) $name ) as $value ) {
				$opt     = $counts[ $name ][ $value ] ?? null;
				$chips[] = array(
					'facet'       => (string) $name,
					'facet_label' => (string) ( $def['label'] ?? $name ),
					'value'       => (string) $value,
					'label'       => (string) ( $opt['label'] ?? $value ),
				);
			}
		}

		// Orden canónico (evidencia: filtros más usados arriba). Estable: a
		// igual `order`, preserva el orden de aparición.
		usort( $facets, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		return array(
			'total'       => $total,
			'facets'      => $facets,
			'chips'       => $chips,
			'has_filters' => ! empty( $chips ) || $filters->has_search(),
			'search'      => $filters->search(),
		);
	}
}
