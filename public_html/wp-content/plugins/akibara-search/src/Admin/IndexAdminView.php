<?php
/**
 * IndexAdminView — renderizado y diagnóstico de la pestaña Índice en el panel de búsqueda.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\HealthReport;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Akibara\Search\Reconciler;
use Akibara\Search\Search;
use Akibara\Search\SearchAdminSection;
use Akibara\Search\AuditLog;
use Akibara\Search\EditorialDiscountPublication;

/**
 * Vista de estado, servicios, mantenimiento y diagnóstico del motor.
 */
final class IndexAdminView {

	public static function render_health_band( array $data, array $verdict ): void {
		$state  = (string) $verdict['verdict'];
		$titles = array(
			HealthReport::SANO           => 'Índice saludable',
			HealthReport::ATENCION       => 'El buscador requiere atención',
			HealthReport::RECONSTRUYENDO => 'Reconstrucción en curso',
		);

		$attrs = '';
		if ( HealthReport::RECONSTRUYENDO === $state ) {
			// El poll (admin-panel.js) solo se activa con este estado; manda
			// X-WP-Nonce (wp_rest) para la cookie-auth del REST admin.
			$attrs = sprintf(
				' data-endpoint="%s" data-nonce="%s"',
				esc_url( rest_url( 'akibara/v1/search/admin-status' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) )
			);
		}

		printf(
			'<div class="akb-health-band akb-health--%1$s" data-akb-search-status data-state="%1$s"%2$s>',
			esc_attr( $state ),
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado arriba.
		);
		printf(
			'<p class="akb-health-verdict"><span class="akb-health-dot" aria-hidden="true"></span><strong>%s</strong></p>',
			esc_html( $titles[ $state ] ?? $state )
		);

		$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : null;
		if ( HealthReport::RECONSTRUYENDO === $state && null !== $rebuild ) {
			printf(
				'<p data-akb-progress aria-live="polite">Reconstruyendo… %s de %s productos · comenzó %s</p>',
				esc_html( number_format_i18n( (int) $rebuild['done'] ) ),
				esc_html( number_format_i18n( (int) $rebuild['total'] ) ),
				esc_html( self::stamp( (int) $rebuild['started_at'] ) )
			);
		} else {
			$last = is_array( $data['last_rebuild'] ?? null ) ? $data['last_rebuild'] : array();
			printf(
				'<p>%s de %s productos indexados%s</p>',
				esc_html( number_format_i18n( (int) ( $data['docs'] ?? 0 ) ) ),
				esc_html( number_format_i18n( (int) ( $data['eligible'] ?? 0 ) ) ),
				isset( $last['finished_at'] )
					? esc_html( ' · última reconstrucción ' . self::stamp( (int) $last['finished_at'] ) )
					: ''
			);
		}

		$reasons = is_array( $verdict['reasons'] ?? null ) ? $verdict['reasons'] : array();
		if ( ! empty( $reasons ) ) {
			echo '<ul>';
			foreach ( $reasons as $reason ) {
				printf(
					'<li>%s%s</li>',
					esc_html( (string) ( $reason['message'] ?? '' ) ),
					self::reason_cta( (string) ( $reason['cta'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado dentro del helper.
				);
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * CTA contextual de una razón (HTML listo para imprimir; '' si no aplica).
	 *
	 * @param string $cta Código de CTA del veredicto.
	 * @return string
	 */
	public static function reason_cta( string $cta ): string {
		$labels = array(
			'retry'     => 'Reintentar reconstrucción',
			'rebuild'   => 'Reconstruir índice',
			'reconcile' => 'Verificar consistencia',
		);
		if ( 'flags' === $cta ) {
			// Enlaza directamente a la pestaña de Ajustes para revisar o conmutar los servicios del motor.
			return sprintf(
				' <a href="%s">Revisar los servicios en Ajustes</a>',
				esc_url( add_query_arg( array( 'page' => SearchAdminSection::PAGE_SLUG, 'sub' => 'ajustes' ), admin_url( 'admin.php' ) ) )
			);
		}
		if ( ! isset( $labels[ $cta ] ) ) {
			return ''; // desconocidos: el mensaje ya dice qué hacer.
		}
		return sprintf( ' <a href="#akb-search-maintenance">%s ↓</a>', esc_html( $labels[ $cta ] ) );
	}

	/**
	 * Muestra el estado individual de cada servicio del motor de búsqueda.
	 * Los servicios inactivos se representan en gris neutral (estado legítimo por configuración),
	 * reservando las advertencias para inconsistencias efectivas del sistema.
	 *
	 * @param array<string, mixed> $flags     Flags de HealthReport::collect().
	 * @param array<string, mixed> $editorial Estado de la publicación editorial (mismo collect()).
	 * @param array<string, mixed> $semantic  Autoridad y publicación de la foto semántica (mismo collect()).
	 * @return void
	 */
	public static function render_services( array $flags, array $editorial = array(), array $semantic = array() ): void {
		echo '<h2>Servicios</h2>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( array(
			'indexing' => array( 'Indexación automática', 'Activa', 'Apagada' ),
			'suggest'  => array( 'Autocompletado', 'Activo', 'Apagado' ),
			'facets'   => array( 'Filtros del catálogo', 'Activos', 'Apagados' ),
		) as $key => $labels ) {
			$flag       = is_array( $flags[ $key ] ?? null ) ? $flags[ $key ] : array();
			$configured = ! empty( $flag['configured'] );
			$effective  = ! empty( $flag['effective'] );
			if ( $effective ) {
				$status = '<span style="color:#16803d">' . esc_html( $labels[1] ) . '</span>';
			} elseif ( $configured ) {
				// Encendido por el dueño, pero el buscador no lo puede servir: el
				// interruptor NO es el culpable y el panel no puede insinuarlo.
				$status = '<span style="color:#996800">Encendido, pero no disponible ahora</span>';
			} else {
				$status = '<span style="color:#646970">' . esc_html( $labels[2] ) . ' (flag OFF)</span>';
			}
			$status .= sprintf(
				'<br><small>Configurado: %s · efectivo: %s</small>',
				$configured ? 'sí' : 'no',
				$effective ? 'sí' : 'no'
			);
			// «No disponible» sin causa dejaba al operador sin saber si esperar o
			// actuar, y con UNA sola causa posible (la editorial) el panel culpaba a
			// «precios y facetas» de cosas que no eran suyas: la causa sale ahora de
			// la fila misma (service_gate_reason()).
			if ( $configured && ! $effective ) {
				$status .= '<br><small>' . self::service_gate_reason( $key, $flag, $editorial, $semantic ) . '</small>';
			}
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $labels[0] ),
				$status // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido arriba con esc_html en cada parte variable; el resto son literales.
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Causa de que UN servicio guardado encendido no esté efectivo. HTML escapado.
	 *
	 * Hasta 0.1.62 toda fila «no disponible» salía con la causa de la
	 * publicación editorial, y el panel culpaba a «precios y facetas» de tres
	 * cosas que no eran suyas:
	 *
	 *  - Indexación: su efectivo es la foto semántica más el filtro; NUNCA
	 *    depende de los precios publicados.
	 *  - La foto semántica ilegible o un filtro `akb_search_*_enabled` apagan el
	 *    servicio antes de llegar al gate de servido; con el autocompletado así,
	 *    la ruta ni se registra (`Search::register()`) y responde 404, no 503.
	 *  - Un ajuste del buscador guardado y todavía sin publicar cierra el gate
	 *    igual que un cambio de precio, y se arregla solo al publicarse.
	 *
	 * @param string               $key       indexing|suggest|facets.
	 * @param array<string, mixed> $flag      Fila {configured, published, effective}.
	 * @param array<string, mixed> $editorial EditorialDiscountPublication::status().
	 * @param array<string, mixed> $semantic  {available, pending} de HealthReport::collect().
	 * @return string
	 */
	public static function service_gate_reason( string $key, array $flag, array $editorial, array $semantic ): string {
		if ( 'indexing' === $key || empty( $flag['published'] ) ) {
			if ( false === ( $semantic['available'] ?? null ) ) {
				return esc_html( 'Está guardado encendido, pero el buscador no puede leer sus ajustes (alguno tiene un valor dañado) y mientras tanto lo trata como apagado.' );
			}
			return esc_html(
				sprintf(
					'Está guardado encendido, pero un filtro de código (%s) lo mantiene apagado.',
					'akb_search_' . $key . '_enabled'
				)
			);
		}
		if ( true === ( $semantic['pending'] ?? null ) ) {
			return esc_html( 'Guardado; se aplica al publicar: el buscador está publicando un cambio de sus ajustes y lo activa en cuanto termine (segundos, o al acabar la reconstrucción en curso).' );
		}

		return self::editorial_gate_reason( $editorial );
	}

	/**
	 * Causa legible de que un servicio configurado no esté efectivo, más el
	 * enlace a Mantenimiento. Devuelve HTML ya escapado.
	 *
	 * @param array<string, mixed> $editorial EditorialDiscountPublication::status().
	 * @return string
	 */
	public static function editorial_gate_reason( array $editorial ): string {
		$causes = array(
			'materialization_stale'      => 'Los precios y facetas publicados quedaron atrás del catálogo: vuelve solo al terminar la próxima reconstrucción.',
			'state_missing_or_invalid'   => 'Falta el registro de la última publicación de precios y facetas: hace falta una reconstrucción completa.',
			'receipt_missing_or_invalid' => 'Falta el comprobante exacto de la última publicación de precios y facetas: hace falta una reconstrucción completa.',
			'source_snapshot_unreadable' => 'No se pudo leer la configuración de descuentos editoriales; hasta resolverlo el buscador no publica cambios.',
		);
		$code = (string) ( $editorial['error'] ?? '' );
		$text = $causes[ $code ] ?? 'La publicación de precios y facetas todavía no está lista.';

		// «No se pudo leer la configuración» sin decir CUÁL dejaba al dueño
		// reintentando a ciegas (A1 de la revisión de 0.1.63): se nombra el dato
		// y la pantalla donde se corrige, tal como los decide el lector.
		$rotos = is_array( $editorial['broken_options'] ?? null ) ? $editorial['broken_options'] : array();
		if ( 'source_snapshot_unreadable' === $code && array() !== $rotos ) {
			$partes = array();
			foreach ( $rotos as $roto ) {
				$label = is_array( $roto ) ? (string) ( $roto['label'] ?? '' ) : '';
				$donde = is_array( $roto ) ? (string) ( $roto['where'] ?? '' ) : '';
				if ( '' !== $label ) {
					$partes[] = '' === $donde ? $label : $label . ' (' . $donde . ')';
				}
			}
			if ( array() !== $partes ) {
				$text = 'No se pudo leer la configuración de descuentos editoriales: tiene un valor que el buscador no puede leer en '
					. implode( ', ', $partes )
					. '. Corrígelo ahí; hasta entonces el buscador no publica cambios y volver a guardar otra cosa no lo arregla.';
			}
		}

		$boundary = $editorial['next_boundary_at'] ?? null;
		if ( is_int( $boundary ) && $boundary > 0 ) {
			$text .= ' Próximo cambio de la ventana de descuentos: ' . self::stamp( $boundary ) . '.';
		}

		return esc_html( $text ) . ' <a href="#akb-search-maintenance">Ir a Mantenimiento ↓</a>';
	}

	/**
	 * Estado: cobertura contra elegibles, última reconstrucción con fecha
	 * exacta + duración, y consistencia con el tamaño de la muestra.
	 *
	 * @param array<string, mixed> $data Insumos de HealthReport::collect().
	 * @return void
	 */
	public static function render_state_table( array $data ): void {
		$docs     = (int) ( $data['docs'] ?? 0 );
		$eligible = (int) ( $data['eligible'] ?? 0 );

		echo '<h2>Estado</h2>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px"><tbody>';
		printf(
			'<tr><th scope="row">Cobertura</th><td>%s · %s / %s productos elegibles</td></tr>',
			esc_html( $eligible > 0 ? floor( $docs * 100 / $eligible ) . '%' : '—' ),
			esc_html( number_format_i18n( $docs ) ),
			esc_html( number_format_i18n( $eligible ) )
		);

		// Informa si existe una reconstrucción pendiente y su motivo.
		// Los detalles de bajo nivel del protocolo residen en el desplegable de diagnóstico técnico.
		self::render_pending_rebuild_row();

		// history[0] trae started_at (duración); el fallback OPTION_LAST cubre
		// instalaciones anteriores al historial.
		$history = RebuildJob::history();
		$last    = ! empty( $history )
			? $history[0]
			: ( is_array( $data['last_rebuild'] ?? null ) ? $data['last_rebuild'] : array() );
		if ( isset( $last['finished_at'] ) ) {
			$bits   = array( self::stamp( (int) $last['finished_at'] ) );
			$bits[] = number_format_i18n( (int) ( $last['docs'] ?? 0 ) ) . ' productos';
			if ( ! empty( $last['started_at'] ) && (int) $last['finished_at'] >= (int) $last['started_at'] ) {
				$bits[] = self::duration( (int) $last['started_at'], (int) $last['finished_at'] );
			}
			printf(
				'<tr><th scope="row">Última reconstrucción</th><td>%s%s</td></tr>',
				esc_html( implode( ' · ', $bits ) ),
				isset( $last['error'] )
					? '<br><span style="color:#b32d2e">' . esc_html( self::error_label( (string) $last['error'] ) ) . '</span>'
					: ''
			);
		} else {
			echo '<tr><th scope="row">Última reconstrucción</th><td>sin reconstrucciones registradas</td></tr>';
		}

		$reconcile = is_array( $data['reconcile_last'] ?? null ) ? $data['reconcile_last'] : array();
		if ( isset( $reconcile['ran_at'] ) ) {
			// Mismo conjunto de divergencias que HealthReport::verdict(): si el
			// panel y el veredicto contaran distinto, la banda diría "1
			// diferencia corregida" y la tabla "sin diferencias" para la misma
			// corrida. `orphan_facets` (facetas con term_id ya borrado) entra
			// acá igual que `stale`.
			$issues  = (int) ( $reconcile['missing'] ?? 0 ) + (int) ( $reconcile['orphaned'] ?? 0 )
				+ (int) ( $reconcile['divergent'] ?? 0 ) + (int) ( $reconcile['stale'] ?? 0 )
				+ (int) ( $reconcile['orphan_facets'] ?? 0 );
			$checked = (int) ( $reconcile['checked'] ?? 0 );
			printf(
				'<tr><th scope="row">Consistencia</th><td>%s — %s</td></tr>',
				esc_html( self::stamp( (int) $reconcile['ran_at'] ) ),
				0 === $issues
					? esc_html( sprintf( 'sin diferencias (%d productos revisados al azar)', $checked ) )
					: esc_html( sprintf( 1 === $issues ? '%d diferencia corregida' : '%d diferencias corregidas', $issues ) )
			);
		} else {
			echo '<tr><th scope="row">Consistencia</th><td>todavía no corre (se agenda sola, una vez al día)</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Renderiza la fila de reconstrucción pendiente con su motivo operativo y fecha de solicitud.
	 * Permite visibilidad directa sobre tareas encoladas antes de su ejecución.
	 *
	 * @return void
	 */
	public static function render_pending_rebuild_row(): void {
		$request = Install::pending_rebuild_request();
		if ( ! is_array( $request ) ) {
			return;
		}

		$bits = array( 'Motivo: ' . self::rebuild_reason_label( (string) ( $request['reason'] ?? '' ) ) );
		if ( (int) ( $request['requested_at'] ?? 0 ) > 0 ) {
			$bits[] = 'pedida ' . self::stamp( (int) $request['requested_at'] );
		}
		if ( (int) ( $request['not_before'] ?? 0 ) > time() ) {
			$bits[] = 'empieza ' . self::stamp( (int) $request['not_before'] );
		}

		printf(
			'<tr><th scope="row">Reconstrucción pendiente</th><td>%s</td></tr>',
			esc_html( implode( ' · ', $bits ) )
		);
	}

	/**
	 * Motivo técnico de una solicitud de reconstrucción → lenguaje de tienda.
	 * Desconocido cae al código crudo: perder la señal es peor que mostrarla
	 * fea, y los motivos nuevos aparecen sin tocar este mapa.
	 *
	 * @param string $reason Código acuñado por Install::request_rebuild().
	 * @return string
	 */
	public static function rebuild_reason_label( string $reason ): string {
		$labels = array(
			'manual'                            => 'Pedida desde el panel',
			'schedule_while_active'             => 'Se pidió otra mientras ya había una corriendo',
			'schema_migration'                  => 'Actualización del plugin (cambio de estructura)',
			'facet_registry_changed'            => 'Cambiaron las facetas',
			'editorial_materialization_pending' => 'Cambiaron los descuentos por editorial',
			'visibility_option_changed'         => 'Cambió la visibilidad del catálogo',
			'term_edit'                         => 'Se editó una categoría o etiqueta',
			'term_delete'                       => 'Se borró una categoría o etiqueta',
			'term_fanout'                       => 'Cambio masivo de categorías o etiquetas',
			'runtime_schema_unready'            => 'El índice no estaba listo para escribir y quedó deuda',
			'live_debt_persist_failed'          => 'No se pudo anotar un cambio del catálogo',
			'live_batch_persist_failed'         => 'No se pudo anotar un lote de cambios del catálogo',
			'source_barrier_debt_failed'        => 'No se pudo procesar la cola de cambios',
			'source_barrier_wake_failed'        => 'No se pudo despertar la cola de cambios',
			'product_save_source_unreadable'    => 'No se pudo leer un producto al guardarlo',
			'post_state_source_unreadable'      => 'No se pudo leer el estado de un producto',
			'variation_parent_unreadable'       => 'No se pudo leer el producto padre de una variación',
			'variation_old_parent_unreadable'   => 'No se pudo leer el producto padre anterior de una variación',
			'term_relationship_source_unreadable' => 'No se pudieron leer las categorías de un producto',
			'term_fanout_source_unreadable'     => 'No se pudieron leer los productos de una categoría',
			'term_delete_snapshot_unreadable'   => 'No se pudo leer la categoría borrada',
			'term_delete_children_unreadable'   => 'No se pudieron leer las subcategorías de la categoría borrada',
			// EditorialDiscountPublication acuña estos al vuelo (stage_term /
			// stage_global): son los cambios de descuento por editorial.
			'term_meta_update'                  => 'Cambió el descuento de una editorial',
			'term_meta_add'                     => 'Se agregó el descuento de una editorial',
			'term_meta_delete'                  => 'Se quitó el descuento de una editorial',
			'term_meta_delete_all'              => 'Se quitaron descuentos de editorial',
		);
		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		// `option_update_<option>` y familia: el motivo trae la option adentro.
		foreach ( array( 'option_update_' => 'Cambió el ajuste', 'option_add_' => 'Se creó el ajuste', 'option_delete_' => 'Se borró el ajuste' ) as $prefix => $label ) {
			if ( str_starts_with( $reason, $prefix ) ) {
				return $label . ' «' . substr( $reason, strlen( $prefix ) ) . '»';
			}
		}

		return '' !== $reason ? $reason : 'sin motivo registrado';
	}

	/**
	 * Mantenimiento: el botón de rebuild MORFA según el estado — en curso =
	 * deshabilitado con progreso; detenido/fallido = "Reintentar" (reanuda el
	 * estado durable, con preflight server-side); normal = reconstruir.
	 *
	 * @param array<string, mixed> $data    Insumos de HealthReport::collect().
	 * @param array<string, mixed> $verdict Salida de HealthReport::verdict().
	 * @return void
	 */
	public static function render_maintenance( array $data, array $verdict ): void {
		$state = (string) $verdict['verdict'];
		$retry = false;
		foreach ( ( is_array( $verdict['reasons'] ?? null ) ? $verdict['reasons'] : array() ) as $reason ) {
			$retry = $retry || 'retry' === ( $reason['cta'] ?? '' );
		}
		$blocked = self::rebuild_state_is_blocked( is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : null );

		echo '<h2 id="akb-search-maintenance">Mantenimiento</h2>';
		echo '<p class="description">Reconstruye el índice después de cambiar taxonomías o contextos de facetas, o si faltan productos. Se ejecuta en segundo plano y la tienda sigue disponible.</p>';

		// Fase terminal: el botón normal POSTearía y no pasaría nada (schedule()
		// sale temprano) — mostrarlo era prometer una acción inexistente. Se
		// reemplaza por el estado real y lo que sí se sabe: la tienda no se cayó.
		if ( $blocked ) {
			$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
			$detail  = trim( (string) ( $rebuild['error'] ?? '' ) );
			printf(
				'<div class="notice notice-error inline"><p><strong>La reconstrucción quedó bloqueada%s.</strong> La tienda sigue sirviendo el índice anterior, completo: no se perdió nada y ningún cliente ve un catálogo vacío. No se reanuda sola ni desde esta pantalla: el protocolo no toca las tablas sin diagnóstico, y por eso acá no hay botón de reintento (el que había no hacía nada). Revisa «Diagnóstico técnico», más abajo, y el error log antes de intervenir.</p>%s</div>',
				'' !== $detail ? esc_html( ' (' . $detail . ')' ) : '',
				// En caso de bloqueo huérfano de topología, se guía el procedimiento
				// canónico de rescate respetando el orden estricto de saneamiento.
				'orphan_topology_without_state' === $detail
					? '<p><strong>Este caso concreto sí tiene arreglo conocido:</strong> <code>wp akb-search rescue</code> (o <code>bash ops/rescate-buscador.sh produccion</code>, que lo llama y deja la foto de antes y después). El comando suelta las tablas sombra, retira el estado y arranca un índice nuevo, en ese orden; si hay una copia de respaldo del índice o un índice que sí avanza, no toca nada y lo dice.</p>'
					: ''
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
			echo '<input type="hidden" name="action" value="' . esc_attr( SearchAdminSection::ACTION_RECONCILE ) . '">';
			wp_nonce_field( SearchAdminSection::NONCE_RECONCILE );
			echo '<button type="submit" class="button button-secondary">Verificar consistencia</button>';
			echo '</form>';
			echo '<p class="description">La verificación compara el índice contra el catálogo, repara las diferencias que encuentra y deja el resultado en «Estado». Es segura con la reconstrucción detenida.</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SearchAdminSection::ACTION_REBUILD ) . '">';
		wp_nonce_field( SearchAdminSection::NONCE_REBUILD );
		if ( HealthReport::RECONSTRUYENDO === $state ) {
			$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
			printf(
				'<button type="submit" class="button" data-akb-rebuild-btn disabled>Reconstruyendo… %s de %s</button>',
				esc_html( number_format_i18n( (int) ( $rebuild['done'] ?? 0 ) ) ),
				esc_html( number_format_i18n( (int) ( $rebuild['total'] ?? 0 ) ) )
			);
		} elseif ( $retry ) {
			echo '<input type="hidden" name="force" value="1">';
			echo '<button type="submit" class="button button-primary" data-akb-rebuild-btn>Reintentar reconstrucción</button>';
		} else {
			echo '<button type="submit" class="button button-secondary" data-akb-rebuild-btn>Reconstruir índice</button>';
		}
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SearchAdminSection::ACTION_RECONCILE ) . '">';
		wp_nonce_field( SearchAdminSection::NONCE_RECONCILE );
		echo '<button type="submit" class="button button-secondary">Verificar consistencia</button>';
		echo '</form>';
		echo '<p class="description">La verificación compara el índice contra el catálogo, repara las diferencias que encuentra y deja el resultado en «Estado».</p>';
	}

	/**
	 * ¿El estado durable quedó en una fase terminal de la que el panel NO puede
	 * salir? BLOCKED/ABORTED —o un `error` persistido— significan que ni el
	 * botón normal ni el force reaniman el run (RebuildJob::schedule sale
	 * temprano ante `is_terminal_phase`), y HealthReport devuelve esa razón con
	 * cta '' (nunca 'retry'). Distinguirlo es lo único que separa «esperá, hay
	 * uno corriendo» de «esto no se destraba solo».
	 *
	 * @param array<string, mixed>|null $rebuild Estado del rebuild (RebuildJob::status()).
	 * @return bool
	 */
	public static function rebuild_state_is_blocked( ?array $rebuild ): bool {
		if ( ! is_array( $rebuild ) ) {
			return false;
		}
		$phase = (string) ( $rebuild['phase'] ?? '' );

		return in_array( $phase, array( RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ), true )
			|| '' !== trim( (string) ( $rebuild['error'] ?? '' ) );
	}

	/**
	 * Divulgación progresiva: métricas internas, historial, auditoría y CLI
	 * viven acá — fuera de la lectura principal del operador.
	 *
	 * @param array<string, mixed> $data Insumos de HealthReport::collect().
	 * @return void
	 */
	public static function render_diagnostics( array $data ): void {
		echo '<details class="akb-search-diagnostics" style="margin-top:24px;max-width:840px">';
		echo '<summary><strong>Diagnóstico técnico y comandos CLI</strong></summary>';

		self::render_counts_and_protocol_table( $data );
		self::render_history_table( RebuildJob::history() );
		self::render_audit_log_table( AuditLog::entries() );

		echo '<p class="description" style="margin-top:12px">CLI: <code>wp akb-search rebuild</code> · <code>wp akb-search status</code> · <code>wp akb-search reconcile</code></p>';
		echo '</details>';
	}

	/**
	 * Tabla de conteos y métricas del protocolo durable.
	 *
	 * @param array<string, mixed> $data Insumos de HealthReport.
	 * @return void
	 */
	private static function render_counts_and_protocol_table( array $data ): void {
		global $wpdb;

		$counts = array( 'Productos indexados (docs)' => (int) ( $data['docs'] ?? 0 ) );
		foreach ( array(
			'Tokens del índice'     => Install::tokens_table(),
			'Valores de filtros'    => Install::facets_table(),
			'Consultas registradas' => Install::qlog_table(),
		) as $label => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- {$table} recorre Install::tokens_table()/facets_table()/qlog_table(), las tres $wpdb->prefix + literal fijo, y el COUNT no lleva ningún valor que preparar; la lectura directa es deliberada: son conteos de diagnóstico que deben verse en vivo, sin caché.
			$counts[ $label ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px;margin-top:12px"><tbody>';
		foreach ( $counts as $label => $n ) {
			printf( '<tr><th scope="row">%s</th><td>%s filas</td></tr>', esc_html( $label ), esc_html( number_format_i18n( $n ) ) );
		}

		// Métricas técnicas del protocolo durable (fase, topología y estado de la cola):
		$rebuild  = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
		$work     = is_array( $data['work'] ?? null ) ? $data['work'] : array();
		$phase    = ! empty( $rebuild ) ? (string) ( $rebuild['phase'] ?? '—' ) : 'sin reconstrucción activa';
		$topology = (string) ( $rebuild['topology'] ?? ( $data['topology'] ?? '—' ) );
		$pending  = (int) ( $work['pending'] ?? ( $rebuild['work_pending'] ?? 0 ) );
		$leased   = (int) ( $work['leased'] ?? ( $rebuild['work_leased'] ?? 0 ) );
		$blocked  = (int) ( $work['blocked'] ?? ( $rebuild['work_blocked'] ?? 0 ) );
		$error    = trim( (string) ( $work['error'] ?? '' ) );
		printf(
			'<tr><th scope="row">Protocolo durable</th><td>Fase %s · topología %s<br>%s pendientes · %s en ejecución · %s bloqueados%s</td></tr>',
			esc_html( $phase ),
			esc_html( $topology ),
			esc_html( number_format_i18n( $pending ) ),
			esc_html( number_format_i18n( $leased ) ),
			esc_html( number_format_i18n( $blocked ) ),
			'' !== $error ? '<br><span style="color:#b32d2e">' . esc_html( $error ) . '</span>' : ''
		);
		echo '</tbody></table></div>';
	}

	/**
	 * Tabla del historial de reconstrucciones.
	 *
	 * @param array<int, array<string, mixed>> $history Entradas históricas.
	 * @return void
	 */
	private static function render_history_table( array $history ): void {
		if ( empty( $history ) ) {
			return;
		}
		echo '<h3>Historial de reconstrucciones</h3>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped"><thead><tr><th scope="col">Inicio</th><th scope="col">Duración</th><th scope="col">Productos</th><th scope="col">Resultado</th></tr></thead><tbody>';
		foreach ( $history as $entry ) {
			$started  = (int) ( $entry['started_at'] ?? 0 );
			$finished = (int) ( $entry['finished_at'] ?? 0 );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $started > 0 ? self::stamp( $started ) : '—' ),
				esc_html( $started > 0 && $finished >= $started ? self::duration( $started, $finished ) : '—' ),
				esc_html( number_format_i18n( (int) ( $entry['docs'] ?? 0 ) ) ),
				self::history_result_html( $entry ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado dentro del helper.
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Tabla del registro de auditoría.
	 *
	 * @param array<int, array<string, mixed>> $audit Entradas de auditoría.
	 * @return void
	 */
	private static function render_audit_log_table( array $audit ): void {
		if ( empty( $audit ) ) {
			return;
		}
		echo '<h3>Registro de cambios</h3>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped"><thead><tr><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Acción</th><th scope="col">Detalle</th></tr></thead><tbody>';
		foreach ( array_slice( $audit, 0, 15 ) as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( self::stamp( (int) ( $entry['t'] ?? 0 ) ) ),
				esc_html( (string) ( $entry['user'] ?? '' ) ),
				esc_html( self::audit_action_label( (string) ( $entry['action'] ?? '' ) ) ),
				esc_html( self::audit_detail( $entry ) )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function history_result_html( array $entry ): string {
		$result = (string) ( $entry['result'] ?? '' );
		if ( 'ok' === $result ) {
			return '<span style="color:#16803d">OK</span>';
		}
		if ( 'interrumpido' === $result ) {
			return '<span style="color:#996800">Interrumpida</span> — no llegó a terminar (caída o reintento forzado); la tienda siguió con el índice anterior.';
		}
		return '<span style="color:#b32d2e">Error</span> — ' . esc_html( self::error_label( (string) ( $entry['error'] ?? '' ) ) );
	}

	/**
	 * Error técnico → causa + impacto + siguiente paso.
	 *
	 * @param string $code Código de error almacenado.
	 * @return string
	 */
	public static function error_label( string $code ): string {
		if ( 'atomic_swap_failed' === $code ) {
			return 'Falló el intercambio final de tablas. La tienda sigue sirviendo el índice anterior; reintenta la reconstrucción y, si se repite, revisa el error log de la base de datos.';
		}
		// Este código NO es un fallo del swap: es el swap que se NEGÓ a correr.
		// El genérico ("reintenta") es cierto pero no dice lo importante — que
		// el guardarraíl hizo su trabajo y que el índice viejo, completo, sigue
		// en pie. Sin esta distinción el operador lee "error" y asume catálogo
		// caído.
		if ( 'rebuild_incompleto' === $code ) {
			return 'La reconstrucción no alcanzó igualdad exacta con el catálogo (faltaba o sobraba al menos un producto), así que NO se publicó y la tienda sigue sirviendo el índice anterior. El sistema dejó la deuda durable para reintentar; revisa la fase y la topología antes de intervenir.';
		}
		return sprintf( 'Error «%s». La tienda sigue sirviendo el índice anterior; reintenta la reconstrucción.', $code );
	}

	/**
	 * Detalle de una fila del registro, con su contador si se repitió.
	 *
	 * @param array<string,mixed> $entry Fila de `AuditLog::entries()`.
	 * @return string Texto plano (lo escapa quien lo imprime).
	 */
	public static function audit_detail( array $entry ): string {
		$detalle = (string) ( $entry['detail'] ?? '' );
		$veces   = (int) ( $entry['n'] ?? 1 );
		if ( $veces < 2 ) {
			return $detalle;
		}

		$desde = (int) ( $entry['t0'] ?? 0 );

		return trim(
			$detalle . sprintf(
				' — se repitió %s veces%s',
				number_format_i18n( $veces ),
				$desde > 0 ? ' desde el ' . wp_date( 'd-m-Y H:i', $desde ) : ''
			)
		);
	}

	/**
	 * @param string $code Código de acción del AuditLog.
	 * @return string
	 */
	public static function audit_action_label( string $code ): string {
		$labels = array(
			'rebuild_encolado'  => 'Reconstrucción encolada',
			'rebuild_forzado'   => 'Reconstrucción reintentada (force)',
			'reconcile_encolado' => 'Verificación encolada',
			'facets_guardadas'  => 'Facetas guardadas',
			// Acciones del usuario desde el panel:
			'ajustes_guardados' => 'Ajustes guardados',
			// Acciones y eventos del sistema documentados en castellano para legibilidad en auditoría:
			'rescate'                                => 'Rescate del buscador',
			'serving_alarma'                         => 'Alarma del buscador',
			'serving_recovery'                       => 'Buscador caído: reconstrucción pedida',
			'trabajo_huerfano'                       => 'No se pudo barrer el trabajo de corridas anteriores',
			'avance_generacion_rechazado'            => 'Publicación del índice rechazada',
			'index_term_debt_failed'                 => 'No se pudo anotar un cambio de categoría o marca',
			'option_semantica_sin_cercar'            => 'Ajuste del buscador guardado sin reserva previa',
			'rebuild_de_filtros_no_pedido'           => 'Cambiaron los filtros y no se pudo pedir la reconstrucción',
			'option_editorial_sin_cercar'            => 'Ajuste de descuentos guardado sin reserva previa',
			'option_editorial_rechazada'             => 'Ajuste de descuentos rechazado',
			'option_editorial_reparada'              => 'Ajuste de descuentos reparado',
			'deuda_editorial_a_medias'               => 'Cambio de descuentos a medio aplicar',
			'marco_editorial_sin_cerrar'             => 'Cambio de descuentos sin cerrar',
			'rebuild_editorial_retirado'             => 'Reconstrucción por descuentos retirada',
			'recibo_editorial_superado'              => 'Comprobante de descuentos superado',
			'receipt_resellado'                      => 'Comprobante de precios renovado',
			'receipt_resellado_sin_cambio_de_precio' => 'Comprobante de precios renovado (sin cambio de precio)',
			'receipt_reproof_rechazado'              => 'Comprobación de precios rechazada',
			'receipt_reproof_diferido'               => 'Comprobación de precios aplazada',
			'receipt_reproof_carrera'                => 'Comprobación de precios repetida (dos a la vez)',
			'receipt_reproof_valvula'                => 'Comprobación de precios pausada',
		);
		return $labels[ $code ] ?? $code;
	}

	/**
	 * @return void
	 */

	/**
	 * Formatea una marca de tiempo en fecha legible con diferencia relativa.
	 *
	 * @param int $ts Marca de tiempo Unix.
	 * @return string
	 */
	public static function stamp( int $ts ): string {
		if ( $ts <= 0 ) {
			return 'nunca';
		}
		return wp_date( 'd-m-Y H:i', $ts ) . ' (hace ' . human_time_diff( $ts, time() ) . ')';
	}

	/**
	 * Formatea la duración entre dos marcas de tiempo en minutos y segundos.
	 *
	 * @param int $start Inicio (Unix).
	 * @param int $end   Fin (Unix).
	 * @return string
	 */
	public static function duration( int $start, int $end ): string {
		$s = max( 0, $end - $start );
		if ( $s < 60 ) {
			return $s . ' s';
		}
		return floor( $s / 60 ) . ' min ' . ( $s % 60 ) . ' s';
	}
}
