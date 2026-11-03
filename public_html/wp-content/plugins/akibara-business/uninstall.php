<?php
/**
 * Limpieza al eliminar explícitamente Akibara Business.
 *
 * La desactivación conserva datos; la acción "Eliminar" de WordPress es el
 * consentimiento destructivo que retira tablas propias, opciones, term-meta
 * y crons del plugin.
 *
 * Alcance deliberado (auditoría 2026-07-28):
 *   - SÍ: tablas propias (wishlist, back-in-stock), las opciones akb_/akibara_
 *     DE ESTE plugin (+ sus transients), term-meta _akb_*, sus eventos de
 *     WP-Cron (todos con wp_unschedule_hook(), que también retira los que
 *     llevan argumentos) y su cola de Action Scheduler: las listas de
 *     `Utilities\OwnCrons`, las mismas que usa la desactivación.
 *   - NO: los namespaces `akb_` de los otros tres plugins first-party. El
 *     prefijo está compartido; la lista de hermanos vive abajo, junto al
 *     DELETE.
 *   - NO: meta de órdenes (_akb_eta_* en line items / órdenes) ni meta de
 *     productos — son parte del registro comercial e histórico del catálogo;
 *     borrarlos mutilaría órdenes pasadas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Modules/BackInStock/Install.php';
require_once __DIR__ . '/src/Modules/LlmsManifest/LlmsManifest.php';
require_once __DIR__ . '/src/Modules/SeriesFollow/Install.php';
require_once __DIR__ . '/src/Modules/Wishlist/Install.php';
require_once __DIR__ . '/src/Utilities/OwnCrons.php';

// Tablas propias (cada drop() borra también su option de versión de schema).
\Akibara\Business\Modules\BackInStock\Install::drop();
\Akibara\Business\Modules\SeriesFollow\Install::drop();
\Akibara\Business\Modules\Wishlist\Install::drop();

// Trabajo en segundo plano propio —eventos de WP-Cron y cola de Action Scheduler,
// que es la vía normal de casi todo—: desinstalar sin retirarlo deja entradas
// apuntando a callbacks que ya no existen. Las listas son las MISMAS de la
// desactivación —y la de WP-Cron, la de la tarjeta de salud del panel—, y viven
// en un solo sitio: cuando cada superficie llevaba su copia, a ésta le faltó la
// limpieza de la valla de la colección (S-45f49941), a la desactivación tres
// crons y dos grupos de la cola, y a las dos la purga de Cloudflare
// (S-0e169ea5). 2026-09-19.
// `unschedule_all()` usa wp_unschedule_hook(), que retira también los eventos
// agendados CON argumentos; wp_clear_scheduled_hook() sin argumentos no los ve.
\Akibara\Business\Utilities\OwnCrons::unschedule_all();

// Marca técnica de la recompresión de imágenes («con qué calidad ya se revisó»).
// No es registro comercial: sin el plugin no la lee nadie.
delete_post_meta_by_key( '_akb_avif_quality' );
// Temporales que dejó una recompresión muerta (sólo los propios, por prefijo).
// Tres niveles: las portadas viven en `portadas/`, pero el adjunto puede estar en
// `portadas/2026/09/` y ahí también se recodifica (revisión independiente, 2026-09-15).
$akibara_uploads = wp_get_upload_dir();
foreach ( array( '/portadas/', '/portadas/*/', '/portadas/*/*/' ) as $akibara_nivel ) {
	foreach ( (array) glob( $akibara_uploads['basedir'] . $akibara_nivel . '.akb-recomprimir-*.avif' ) as $akibara_temporal ) {
		wp_delete_file( (string) $akibara_temporal );
	}
}

// Los archivos sólo se retiran si coinciden con un hash publicado por el módulo.
\Akibara\Business\Modules\LlmsManifest\LlmsManifest::remove_manifests();

global $wpdb;

// Opciones del plugin por prefijo (akb_ y akibara_) más sus transients.
// El guion bajo se escapa (\_) porque en LIKE es comodín de un caracter.
//
// ══════════════════════════════════════════════════════════════════════════
// FRONTERA DE NAMESPACE — LEER ANTES DE TOCAR EL GLOB
// ══════════════════════════════════════════════════════════════════════════
// El prefijo `akb_` NO es de este plugin: está compartido por los CUATRO
// plugins first-party de la tienda, y este barrido sólo puede llevarse lo
// propio. Cada línea de $akibara_hermanos es un plugin al que este DELETE le
// borraría la configuración entera si faltara:
//
//   - `akb_*` a secas → ESTE plugin (akb_bis_*, akb_wishlist_*,
//                        akb_series_bundles_*, akb_preventas_*, ...).
//   - `akb_search_*`, y los transients `akb_vocab_*`, `akb_lc_*`, `akb_rlm_*`
//                     → akibara-search (índice, vocabulario, facetas,
//                       límites de tasa).
//   - `akb_rewards_*` → akibara-rewards (36 opciones: puntos, canje, reseñas).
//   - `akb_blocks_*`, `akb_pudo_log_*`
//                     → akibara-cart-checkout-blocks, que HOY no tiene
//                       uninstall propio: sus opciones sobreviven a este
//                       barrido y se retiran cuando ese plugin gane el suyo
//                       (traspaso a la tanda de bloques). Dejarlas huérfanas es
//                       reversible; borrarle la configuración al carrito
//                       mientras sigue instalado, no.
//
// El plugin hermano del buscador aplica la exclusión simétrica en su propio
// uninstall.php, y `tests/OptionNamespaceBoundaryTest.php` verifica el
// contrato por los dos lados.
//
// Las opciones `akibara_*` son exclusivas de este plugin y no necesitan filtro.
$akibara_hermanos = array( 'akb\\_search\\_', 'akb\\_vocab\\_', 'akb\\_lc\\_', 'akb\\_rlm\\_', 'akb\\_rewards\\_', 'akb\\_blocks\\_', 'akb\\_pudo\\_log\\_' );

// Y los MU-PLUGINS, que no se desinstalan nunca: siguen vivos después de este
// barrido, así que llevarse su memoria es dejarlos amnésicos sin que nadie lo
// note (hallazgo S-890b95b2). El vigilante del correo perdía su registro de
// cortes —el mismo que existe porque la tienda pasó tres días sin enviar un
// solo correo— y el vigía de Blue volvía a avisar de un problema ya resuelto.
// REAPERTURA 2026-09-19 (S-890b95b2): también se llevaba la memoria del
// TRANSPORTE (no sólo del centinela que lo vigila) — la lista de hasta 20
// correos bloqueados con destinatario y asunto que el dueño necesita para
// saber a quién reescribirle. No son prefijos sino nombres exactos: los
// cuenta test_business_uninstall_excludes_mu_plugin_options().
$akibara_mu_plugins = array(
	'akibara\\_mail\\_transport\\_watch',  // mu-plugins/akibara-mail-transport-watch.php, Watch::OPTION_STATE
	'akibara\\_mail\\_transport\\_outage', // mu-plugins/akibara-correo-smtp.php, Transport::OPTION_OUTAGE
	'akb\\_bluex\\_watch\\_',              // mu-plugins/akibara-bluex-integrity-watch.php, tres marcas
	'akibara\\_debug\\_log\\_rotation\\_checked', // mu-plugins/akibara-debug-log-rotation.php
);
$akibara_hermanos   = array_merge( $akibara_hermanos, $akibara_mu_plugins );

$akibara_exclusiones = array();
foreach ( $akibara_hermanos as $akibara_hermano ) {
	$akibara_exclusiones[] = "option_name NOT LIKE '{$akibara_hermano}%'";
	$akibara_exclusiones[] = "option_name NOT LIKE '\\_transient\\_{$akibara_hermano}%'";
	$akibara_exclusiones[] = "option_name NOT LIKE '\\_transient\\_timeout\\_{$akibara_hermano}%'";
}

// Los prefijos son literales de este archivo, no entrada: `prepare()` no aplica
// a nombres de columna ni a patrones LIKE construidos acá.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE ( option_name LIKE 'akb\\_%'
	    OR option_name LIKE 'akibara\\_%'
	    OR option_name LIKE '\\_transient\\_akb\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_akb\\_%'
	    OR option_name LIKE '\\_transient\\_akibara\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_akibara\\_%' )
	   AND " . implode( "\n\t   AND ", $akibara_exclusiones ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// Term-meta propio (_akb_min_days/_akb_max_days de pa_pais, región de marcas,
// descuentos por taxonomía, guías relacionadas, etc.).
$wpdb->query(
	"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\\_akb\\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// User-meta de «Sigue esta serie»: la baja por serie, la baja global y la marca
// del último envío. El borrado del USUARIO ya se las lleva —por eso el módulo no
// necesita PrivacyHandler propio—, pero desinstalar el plugin sin barrerlas
// dejaría preferencias de un módulo que ya no existe.
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_akb\\_sf\\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
