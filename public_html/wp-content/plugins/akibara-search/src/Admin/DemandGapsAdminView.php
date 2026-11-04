<?php
/**
 * DemandGapsAdminView — visualización de huecos de demanda, búsquedas sin resultado y solicitudes.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\FilterSet;
use Akibara\Search\Fuzzy;
use Akibara\Search\GapDismissals;
use Akibara\Search\Install;
use Akibara\Search\Normalizer;
use Akibara\Search\QueryEngine;
use Akibara\Search\SearchAdminSection;

/**
 * Vista y helpers para el radar de demanda y rescate de búsquedas.
 */
final class DemandGapsAdminView {

	public static function zero_result_rescues( array $queries ): array {
		if ( empty( $queries ) ) {
			return array();
		}

		// La clave incorpora la generación activa del índice: garantiza que la foto
		// de rescate expire de forma natural cuando se publica una nueva versión del catálogo.
		$key    = 'akb_search_zero_rescue_' . md5( (string) get_option( Install::OPTION_INDEX_GEN, '' ) . "\n" . implode( "\n", $queries ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$engine = new QueryEngine( $wpdb );
		$out    = array();
		foreach ( $queries as $query ) {
			Fuzzy::flush();
			$hits = count( $engine->search( $query, false, QueryEngine::MAX_RESULTS, true ) );
			// La disponibilidad se consulta DESPUÉS de la búsqueda, no antes: un
			// error de lectura devuelve cero resultados, y anotar ese cero como
			// «hueco real» convertiría una falla del índice en una tarea de
			// contenido inventada. Mismo orden que SuggestController.
			if ( ! $engine->is_available() ) {
				return $out; // Sin veredicto inventado y sin cachear una foto rota.
			}
			$map = Fuzzy::last_correction();
			$fix = '';
			if ( ! empty( $map ) ) {
				$tokens = array();
				foreach ( explode( ' ', Normalizer::normalize( $query ) ) as $token ) {
					if ( '' !== $token ) {
						$tokens[] = $map[ $token ] ?? $token;
					}
				}
				$fix = implode( ' ', $tokens );
			}
			$out[ $query ] = array(
				'hits' => $hits,
				'fix'  => $fix,
			);
		}
		Fuzzy::flush(); // El side-channel es de request; no dejarlo sucio.

		set_transient( $key, $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Veredicto por fila de «sin resultados». HTML ya escapado.
	 *
	 * @param array{hits: int, fix: string}|null $rescue Medición del motor, o null si no se pudo medir.
	 * @return string
	 */
	public static function zero_result_verdict_html( ?array $rescue ): string {
		if ( null === $rescue ) {
			return '<span style="color:#646970">—</span>';
		}
		$hits = (int) $rescue['hits'];
		if ( 0 === $hits ) {
			return '<strong style="color:#b32d2e">No</strong> — hueco real';
		}
		if ( '' !== (string) $rescue['fix'] ) {
			return sprintf(
				'<span style="color:#996800">Sí, corrigiendo a «%s»</span> — %s resultados',
				esc_html( (string) $rescue['fix'] ),
				esc_html( number_format_i18n( $hits ) )
			);
		}

		// Sin corrección y con resultados: el catálogo cambió DESPUÉS de ese cero.
		return sprintf( '<span style="color:#16803d">Sí</span> — %s resultados hoy', esc_html( number_format_i18n( $hits ) ) );
	}

	/**
	 * Ranking «Los más buscados que no tienes» — la respuesta rápida del panel.
	 *
	 * Recibe los huecos ya filtrados por DemandGaps (typos rescatados y
	 * descartes del dueño quedaron fuera ANTES del recorte). Cada entrada lleva
	 * su acción principal, crear la ficha con el título ya escrito, porque ese
	 * es el flujo del dueño (las fichas las crea él).
	 *
	 * @param array<int, object> $reales Huecos reales `{query_norm, s, s30}`.
	 */
	public static function render_missing_titles( array $reales ): void {
		// El log queda intacto por si el dueño cambia de opinión: los descartes
		// se listan aparte, con su botón de vuelta.
		$descartadas = GapDismissals::all();
		if ( empty( $reales ) && empty( $descartadas ) ) {
			return;
		}

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:16px 20px;margin:8px 0 20px;max-width:760px;overflow-x:auto;min-width:0">';
		echo '<h2 style="margin:0 0 4px">Los más buscados que no tienes</h2>';
		echo '<p class="description" style="margin-top:0">Búsquedas de los últimos 90 días sin ningún resultado, ya descartados los errores de tipeo de títulos que sí existen. Es tu lista corta de compra y fichas.</p>';
		if ( array() !== $reales ) :
		echo '<table class="widefat striped"><thead><tr><th scope="col">Título buscado</th><th scope="col">90 días</th><th scope="col">Este mes</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $reales as $row ) {
			$query = (string) $row->query_norm;
			$s30   = (int) ( $row->s30 ?? 0 );
			printf(
				'<tr><td data-label="Título buscado" style="font-size:14px"><strong>%s</strong></td><td data-label="90 días"><strong>%s</strong></td><td data-label="Este mes">%s</td><td data-label="Acciones">%s</td></tr>',
				esc_html( $query ),
				esc_html( number_format_i18n( (int) $row->s ) ),
				$s30 > 0 ? esc_html( number_format_i18n( $s30 ) ) : '<span style="color:#787c82">—</span>',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper con HTML ya escapado.
				self::create_product_link_html( $query ) . ' · ' . self::zero_result_actions_html( $query ) . ' · ' . self::gap_dismiss_link_html( $query )
			);
		}
		echo '</tbody></table>';
		endif;
		echo '<p class="description">«Este mes» en guion = nadie lo buscó en 30 días: demanda antigua, prioridad baja. «Descartar» la oculta del radar (recuperable); el log se poda solo a 180 días.</p>';
		self::render_dismissed_gaps( $descartadas );
		echo '</div>';
	}

	/**
	 * URL nonceada de una acción del radar. Puente para CUALQUIER superficie
	 * —incluido el Centro de Mando de business, que vive en otro riel— para
	 * que nadie tenga que reproducir a mano el par acción/nonce.
	 *
	 * @param string $action ACTION_GAP_DISMISS o ACTION_GAP_RESTORE.
	 * @param string $query  Búsqueda normalizada.
	 * @return string URL cruda (sin escapar para atributo).
	 */
	public static function gap_action_url( string $action, string $query ): string {
		if ( ! in_array( $action, array( SearchAdminSection::ACTION_GAP_DISMISS, SearchAdminSection::ACTION_GAP_RESTORE ), true ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'query'  => rawurlencode( $query ),
				),
				admin_url( 'admin-post.php' )
			),
			$action
		);
	}

	/**
	 * Enlace «Descartar» de una búsqueda del radar (admin-post con nonce).
	 *
	 * @param string $query Búsqueda normalizada.
	 */
	public static function gap_dismiss_link_html( string $query ): string {
		return sprintf(
			'<a href="%s" style="color:#b32d2e">Descartar<span class="screen-reader-text"> «%s» del radar</span></a>',
			esc_url( self::gap_action_url( SearchAdminSection::ACTION_GAP_DISMISS, $query ) ),
			esc_html( $query )
		);
	}

	/**
	 * Bloque plegable de descartadas con su «Restaurar».
	 *
	 * @param array<string,int> $descartadas Mapa query → timestamp.
	 * @return void
	 */
	public static function render_dismissed_gaps( array $descartadas ): void {
		if ( array() === $descartadas ) {
			return;
		}

		arsort( $descartadas );
		printf( '<details style="margin-top:8px"><summary style="cursor:pointer">Descartadas (%d)</summary><ul style="margin:8px 0 0">', count( $descartadas ) );
		foreach ( $descartadas as $query => $ts ) {
			printf(
				'<li><code>%s</code> <span style="color:#787c82">(%s)</span> — <a href="%s">Restaurar</a></li>',
				esc_html( (string) $query ),
				esc_html( date_i18n( 'd M', (int) $ts ) ),
				esc_url( self::gap_action_url( SearchAdminSection::ACTION_GAP_RESTORE, (string) $query ) )
			);
		}
		echo '</ul></details>';
	}

	/**
	 * Descarta una búsqueda del radar y vuelve a la pantalla de origen.
	 *
	 * @return void
	 */
	/**
	 * Restaura una búsqueda descartada.
	 *
	 * @return void
	 */
	/**
	 * Enlace a crear la ficha con el título ya pre-escrito.
	 *
	 * @param string $query Búsqueda normalizada.
	 */
	public static function create_product_link_html( string $query ): string {
		return sprintf(
			'<a class="button button-small button-primary" href="%s">Crear ficha<span class="screen-reader-text"> para «%s»</span></a>',
			esc_url(
				add_query_arg(
					array(
						'post_type'  => 'product',
						'post_title' => rawurlencode( $query ),
					),
					admin_url( 'post-new.php' )
				)
			),
			esc_html( $query )
		);
	}

	/**
	 * Acciones por fila: probar la búsqueda en la tienda y arrancar un sinónimo
	 * con el texto ya cargado. Antes había que retipear la búsqueda en otra
	 * pestaña para cualquiera de las dos cosas.
	 *
	 * @param string $query Búsqueda normalizada.
	 * @return string HTML ya escapado.
	 */
	public static function zero_result_actions_html( string $query ): string {
		$links = array();

		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		if ( '' !== $shop ) {
			// `_buscar` es la búsqueda propia de la tienda (is_shop), no el `?s=`
			// genérico de WordPress. add_query_arg NO codifica los valores nuevos.
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">Probar<span class="screen-reader-text"> «%s» en la tienda (abre en otra pestaña)</span></a>',
				esc_url( add_query_arg( FilterSet::PARAM_SEARCH, rawurlencode( $query ), $shop ) ),
				esc_html( $query )
			);
		}

		$links[] = sprintf(
			'<a href="%s">Crear sinónimo<span class="screen-reader-text"> para «%s»</span></a>',
			esc_url(
				add_query_arg(
					array(
						'page'            => SearchAdminSection::PAGE_SLUG,
						'sub'             => 'sinonimos',
						SearchAdminSection::PARAM_PREFILL => rawurlencode( $query ),
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html( $query )
		);

		return implode( ' · ', $links );
	}

	/**
	 * Día del qlog (`YYYY-MM-DD`) en formato legible; vacío o inválido → «—».
	 *
	 * @param string $day Valor crudo de la columna `day`.
	 * @return string
	 */
	public static function qlog_day_label( string $day ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) {
			return '—';
		}
		$stamp = strtotime( $day . ' 12:00:00 +0000' );

		return false === $stamp ? $day : (string) date_i18n( 'j M Y', $stamp );
	}

	/**
	 * @return void
	 */

}
