<?php
/**
 * Limpieza al eliminar explícitamente Akibara Search.
 *
 * La desactivación conserva el índice; la acción "Eliminar" de WordPress es el
 * consentimiento destructivo que retira tablas, opciones y trabajos propios.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * FRONTERA DE NAMESPACE — LEER ANTES DE TOCAR EL GLOB
 * ══════════════════════════════════════════════════════════════════════════
 * El prefijo `akb_` está COMPARTIDO entre dos plugins:
 *
 *   - `akb_search_*`  → ESTE plugin (akibara-search).
 *   - `akb_*` a secas → akibara-business (`akb_bis_*`, `akb_wishlist_*`,
 *                        `akb_series_bundles_*`, `akb_preventas_*`, ...).
 *
 * Por eso este archivo SIEMPRE filtra por `akb_search_%`, nunca por `akb_%`.
 * Un `DELETE ... LIKE 'akb\_%'` acá borraría la configuración de Business
 * —lista de espera, favoritos, Arma tu Pack— en silencio, y el síntoma
 * aparecería mucho después como "la tienda se comporta raro".
 *
 * Lo mismo con las tablas: `wp_akb_search_*` son de este plugin;
 * `wp_akb_bis_subscribers` y `wp_akb_wishlist` son de Business.
 *
 * (Business aplica la exclusión simétrica en su propio uninstall.php.)
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Tablas propias, incluida la cola durable y los dos juegos recuperables del
// swap. En runtime `_old` se conserva hasta terminar la publicación; uninstall
// es la única frontera donde corresponde eliminar cualquier topología residual.
$akb_search_tables = array(
	$wpdb->prefix . 'akb_search_docs',
	$wpdb->prefix . 'akb_search_docs_new',
	$wpdb->prefix . 'akb_search_docs_old',
	$wpdb->prefix . 'akb_search_tokens',
	$wpdb->prefix . 'akb_search_tokens_new',
	$wpdb->prefix . 'akb_search_tokens_old',
	$wpdb->prefix . 'akb_search_facet_index',
	$wpdb->prefix . 'akb_search_facet_index_new',
	$wpdb->prefix . 'akb_search_facet_index_old',
	$wpdb->prefix . 'akb_search_qlog',
	// Versiones antiguas llegaron a crear estos dos residuos aunque qlog nunca
	// debió participar del swap; se incluyen sólo para una eliminación explícita.
	$wpdb->prefix . 'akb_search_qlog_new',
	$wpdb->prefix . 'akb_search_qlog_old',
	$wpdb->prefix . 'akb_search_work',
);

foreach ( $akb_search_tables as $akb_search_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$akb_search_table}" ); // phpcs:ignore WordPress.DB -- {$akb_search_table} recorre la lista literal de tablas del propio plugin que declara este mismo fichero ($wpdb->prefix + nombre fijo); la sentencia no lleva ningún valor que preparar, y en un uninstall la lectura directa sin caché es el único camino.
}

// Trabajos recurrentes y por lote en Action Scheduler. `as_unschedule_all_actions`
// existe solo si WooCommerce (que lo provee) sigue activo; si no, las acciones
// quedan huérfanas pero inertes: su hook ya no tiene callback.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	// Action Scheduler soporta hook vacío + grupo para cancelar el grupo entero.
	// Así también se retiran pumps/watchdog nuevos y acciones de versiones futuras,
	// sin depender de una enumeración que pueda quedar incompleta.
	as_unschedule_all_actions( '', array(), 'akibara-search' );
}

// Opciones y transients propios. El guion bajo se escapa (\_) porque en LIKE
// es comodín de un carácter. Ver la FRONTERA DE NAMESPACE de arriba: el filtro
// enumera prefijos EXACTOS de este plugin, jamás `akb_%`.
//
// Son CUATRO familias, no una: además de `akb_search_*` el buscador escribe
// `akb_vocab_*` (vocabulario del fuzzy, QueryEngine), `akb_lc_*` (counts de
// facetas, FacetService) y `akb_rlm_*` (contador del rate-limit, Support\
// RateLimit). Con sólo el primer prefijo, desinstalar dejaba las otras tres
// atrás — y con el object cache persistente encendido el GC de core no las
// barre nunca, así que quedaban para siempre.
$akb_search_option_prefixes = array( 'akb\\_search\\_', 'akb\\_vocab\\_', 'akb\\_lc\\_', 'akb\\_rlm\\_' );

$akb_search_conditions = array();
foreach ( $akb_search_option_prefixes as $akb_search_prefix ) {
	$akb_search_conditions[] = "option_name LIKE '{$akb_search_prefix}%'";
	$akb_search_conditions[] = "option_name LIKE '\\_transient\\_{$akb_search_prefix}%'";
	$akb_search_conditions[] = "option_name LIKE '\\_transient\\_timeout\\_{$akb_search_prefix}%'";
}

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- la desinstalación barre options propias una sola vez; no hay caché que consultar ni API de WP que acepte un LIKE por prefijo.
	"DELETE FROM {$wpdb->options} WHERE " . implode( "\n\t OR ", $akb_search_conditions ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- {$wpdb->options} es la tabla de WordPress y $akb_search_conditions se arma sólo con los cuatro prefijos literales de la línea 81: no entra nada de fuera.
);
