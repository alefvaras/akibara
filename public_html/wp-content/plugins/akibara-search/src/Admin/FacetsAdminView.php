<?php
/**
 * FacetsAdminView — renderizado y helpers del tab Facetas en el panel de búsqueda.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\FacetRegistry;
use Akibara\Search\FacetsView;
use Akibara\Search\SearchAdminSection;

/**
 * Vista y helpers para la gestión de facetas del catálogo.
 */
final class FacetsAdminView {

	public static function render_facets( SearchAdminSection $admin ): void {
		$defs       = FacetRegistry::definitions();
		$seed_names = FacetRegistry::seed_names();

		$orders = FacetsView::effective_orders( $defs );
		uksort( $defs, static fn( $a, $b ): int => $orders[ (string) $a ] <=> $orders[ (string) $b ] );

		$datalist_taxes = array();
		foreach ( $defs as $def ) {
			if ( 'taxonomy' === ( $def['type'] ?? '' ) && '' !== (string) ( $def['taxonomy'] ?? '' ) ) {
				$datalist_taxes[ (string) $def['taxonomy'] ] = true;
			}
		}
		foreach ( array_keys( self::eligible_taxonomies() ) as $tax ) {
			$datalist_taxes[ $tax ] = true;
		}
		$capped = self::render_parent_datalists( array_keys( $datalist_taxes ) );

		$admin->render_template(
			'tab-facetas.php',
			array(
				'defs'           => $defs,
				'seed_names'     => $seed_names,
				'eligible_taxes' => self::eligible_taxonomies(),
				'capped'         => $capped,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Opciones de UI válidas para una faceta.
	 *
	 * @param array<string, mixed> $def Definición de faceta.
	 * @return array<string, string>
	 */
	public static function facet_ui_choices( array $def ): array {
		// El texto entre paréntesis es la GUÍA de cuándo usar cada control (regla
		// del proyecto: casillas por defecto para combinar, radio solo si la
		// faceta es realmente excluyente, lista con buscador para taxonomías
		// largas). Restaurado tras el gate adversario P2 — sin el hint, el
		// operador podía poner radio en una faceta combinable o alpha en una corta.
		$choices = array(
			'checkboxes' => 'Casillas (combinables)',
			'radio'      => 'Radio (excluyente)',
		);

		if ( 'taxonomy' === ( $def['type'] ?? '' ) ) {
			$choices['alpha'] = 'Lista con buscador (muchos términos)';
		}

		return $choices;
	}

	/**
	 * Sanitiza la UI de una faceta sin permitir combinaciones inválidas.
	 *
	 * @param string               $ui  Valor recibido del formulario.
	 * @param array<string, mixed> $def Definición de faceta.
	 * @return string
	 */
	public static function sanitize_facet_ui( string $ui, array $def ): string {
		$choices = self::facet_ui_choices( $def );
		if ( isset( $choices[ $ui ] ) ) {
			return $ui;
		}

		$current = (string) ( $def['ui'] ?? 'checkboxes' );
		return isset( $choices[ $current ] ) ? $current : 'checkboxes';
	}

	/**
	 * Label corto para auditoría y notices.
	 *
	 * @param string $ui Slug de UI.
	 * @return string
	 */
	public static function facet_ui_label( string $ui ): string {
		$labels = array(
			'checkboxes' => 'casillas',
			'radio'      => 'radio',
			'alpha'      => 'lista con buscador',
		);

		return $labels[ $ui ] ?? $ui;
	}

	/**
	 * @param string $nonce_action Acción del nonce.
	 * @return void
	 */
	public static function render_parent_datalists( array $taxes ): array {
		$capped = array();
		foreach ( $taxes as $tax ) {
			$candidates = self::parent_candidates( $tax );
			if ( count( $candidates ) > 100 ) {
				$candidates = array_slice( $candidates, 0, 100 );
				$capped[]   = $tax;
			}
			$tax_obj   = get_taxonomy( $tax );
			$tax_label = $tax_obj instanceof \WP_Taxonomy ? (string) $tax_obj->label : $tax;
			printf( '<datalist id="akb-parents-%s">', esc_attr( $tax ) );
			foreach ( $candidates as $term ) {
				printf(
					'<option value="%s" label="%s"></option>',
					esc_attr( $term->name ),
					esc_attr( $term->name . ' — ' . $tax_label )
				);
			}
			echo '</datalist>';
		}
		return $capped;
	}

	/**
	 * Términos con hijos de una taxonomía, ordenados por nombre.
	 *
	 * @param string $taxonomy Slug de la taxonomía.
	 * @return array<int, \WP_Term>
	 */
	public static function parent_candidates( string $taxonomy ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Consulta directa para identificar términos con hijos en la taxonomía indicada sobre $wpdb->term_taxonomy.
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent > 0",
				$taxonomy
			)
		);
		if ( empty( $parent_ids ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => array_map( 'intval', $parent_ids ),
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Resuelve la entrada de contexto (picker) a un term_id. Acepta ID
	 * numérico (backcompat) o nombre exacto contra la taxonomía de ESA
	 * faceta; el datalist puede traer «Nombre — Taxonomía», así que también
	 * se intenta sin el sufijo. Nombre ambiguo (duplicado en la taxonomía) o
	 * inexistente = error — NUNCA first-match silencioso [gate DISEÑO §5].
	 *
	 * @param string $raw      Valor crudo del input.
	 * @param string $taxonomy Taxonomía de la faceta.
	 * @return array{id: int, error: string} error '' = ok (id 0 = sin contexto).
	 */
	public static function resolve_parent_term( string $raw, string $taxonomy ): array {
		$raw = trim( $raw );
		if ( '' === $raw || '0' === $raw ) {
			return array(
				'id'    => 0,
				'error' => '',
			);
		}

		if ( ctype_digit( $raw ) ) {
			$term = get_term( (int) $raw, $taxonomy );
			return $term instanceof \WP_Term
				? array(
					'id'    => (int) $raw,
					'error' => '',
				)
				: array(
					'id'    => 0,
					'error' => 'facets_invalid_parent',
				);
		}

		$variants = array( $raw );
		$dash     = strrpos( $raw, ' — ' );
		if ( false !== $dash ) {
			$variants[] = trim( substr( $raw, 0, $dash ) );
		}

		foreach ( $variants as $name ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'hide_empty' => false,
				)
			);
			if ( ! is_array( $found ) || empty( $found ) ) {
				continue;
			}
			if ( count( $found ) > 1 ) {
				return array(
					'id'    => 0,
					'error' => 'facets_ambiguous_parent',
				);
			}
			return array(
				'id'    => (int) $found[0]->term_id,
				'error' => '',
			);
		}

		return array(
			'id'    => 0,
			'error' => 'facets_invalid_parent',
		);
	}

	/**
	 * Resumen legible de un cambio de facetas (para AuditLog y el notice).
	 * PURA (arrays → string): pineada con tests unit.
	 *
	 * @param array<string, array<string, mixed>> $before Definiciones previas.
	 * @param array<string, array<string, mixed>> $after  Definiciones nuevas.
	 * @return string '' si no cambió nada relevante.
	 */
	public static function facets_diff_summary( array $before, array $after ): string {
		$parts = array();

		foreach ( array_diff_key( $after, $before ) as $name => $def ) {
			$parts[] = sprintf( 'alta %s (%s)', $name, (string) ( $def['taxonomy'] ?? $def['type'] ?? '' ) );
		}
		foreach ( array_keys( array_diff_key( $before, $after ) ) as $name ) {
			$parts[] = 'baja ' . $name;
		}

		foreach ( array_intersect_key( $after, $before ) as $name => $def ) {
			$prev = $before[ $name ];

			$label_before = (string) ( $prev['label'] ?? $name );
			$label_after  = (string) ( $def['label'] ?? $name );
			if ( $label_before !== $label_after ) {
				$parts[] = sprintf( '%s: etiqueta «%s»→«%s»', $name, $label_before, $label_after );
			}

			$vis_before = ! isset( $prev['visible'] ) || $prev['visible'];
			$vis_after  = ! isset( $def['visible'] ) || $def['visible'];
			if ( $vis_before !== $vis_after ) {
				$parts[] = $name . ( $vis_after ? ': ahora visible' : ': ahora oculta' );
			}

			$ui_before = (string) ( $prev['ui'] ?? 'checkboxes' );
			$ui_after  = (string) ( $def['ui'] ?? 'checkboxes' );
			if ( $ui_before !== $ui_after ) {
				$parts[] = sprintf(
					'%s: control %s→%s',
					$name,
					self::facet_ui_label( $ui_before ),
					self::facet_ui_label( $ui_after )
				);
			}

			$parent_before = (int) ( $prev['parent_term'] ?? 0 );
			$parent_after  = (int) ( $def['parent_term'] ?? 0 );
			if ( $parent_before !== $parent_after ) {
				$parts[] = sprintf(
					'%s: contexto %s→%s',
					$name,
					$parent_before > 0 ? (string) $parent_before : '—',
					$parent_after > 0 ? (string) $parent_after : '—'
				);
			}
		}

		// Reorden puro: mismas claves comunes, secuencia distinta.
		$common_before = array_values( array_intersect( array_keys( $before ), array_keys( $after ) ) );
		$common_after  = array_values( array_intersect( array_keys( $after ), array_keys( $before ) ) );
		if ( $common_before !== $common_after ) {
			$parts[] = 'orden actualizado';
		}

		$summary = implode( ' · ', $parts );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $summary ) > 280 ) {
			return mb_substr( $summary, 0, 277 ) . '…';
		}
		return $summary;
	}

	/**
	 * Taxonomías aptas para registrar como faceta nueva: públicas y con UI de WordPress.
	 * Aplica la misma regla uniforme al renderizar el selector y al validar la petición POST (`public && show_ui`),
	 * asegurando que sólo taxonomías legítimas de catálogo sean configurables.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function eligible_taxonomies(): array {
		$eligible = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
			if ( $tax->public && $tax->show_ui ) {
				$eligible[ $tax->name ] = $tax;
			}
		}
		return $eligible;
	}

	/**
	 * Fecha exacta combinada con antigüedad relativa para claridad en métricas administrativas.
	 *
	 * @param int $ts Timestamp Unix.
	 * @return string
	 */

}
