<?php
/**
 * OwnCrons — el trabajo en segundo plano que agenda este plugin, en un solo lugar:
 * sus eventos de WP-Cron y su cola de Action Scheduler.
 *
 * Tres superficies necesitan la misma lista y cada una llevaba su copia a mano:
 * la desactivación (`akibara-business.php`), la desinstalación (`uninstall.php`)
 * y la tarjeta «Alertas del Sistema» del Centro de Mando. Las copias divergieron
 * como divergen siempre las copias: la desactivación llegó a tener 6 entradas
 * contra 8 de la desinstalación (), la desinstalación se dejó fuera la
 * valla de la colección () y la tarjeta vigilaba 3 de los 8 eventos
 * recurrentes mientras su propio comentario hablaba del manifiesto, que no
 * vigilaba. Cerrados juntos el: ahora las
 * tres leen de aquí, y un evento nuevo se añade en UN sitio.
 *
 * La revisión independiente de ese mismo día encontró que el arreglo se había
 * quedado a medias: unificó WP-Cron y dejó, en los mismos dos ficheros, la OTRA
 * lista a mano —la de Action Scheduler, que es la vía normal de casi todo el
 * trabajo del plugin— ya divergida: la desactivación retiraba 3 grupos y 3
 * hooks, la desinstalación 5 y 5, y la purga de Cloudflare no la retiraba
 * ninguna de las dos (segunda pasada). `QUEUE` la trae aquí.
 *
 * SIN DEPENDENCIAS, y es un requisito, no un gusto: este fichero se carga con
 * `require_once` desde la desactivación —donde, si WooCommerce está inactivo, el
 * autoloader nunca llegó a registrarse— y desde `uninstall.php`, que corre sin
 * el plugin cargado. Por eso los hooks y los grupos van como literales y no como
 * `ReadyToShipCron::HOOK`: referenciar la constante de otra clase desde ahí es
 * un fatal. Que cada literal siga siendo el de su módulo, y que no falte ni
 * sobre ninguno, lo vigila `tests/CronsQueNoQuedanHuerfanosTest.php`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Fuente única de los eventos de WP-Cron y de la cola de Action Scheduler propios.
 */
final class OwnCrons {

	/**
	 * Eventos RECURRENTES: hook => cómo se lo nombra al dueño en la tarjeta.
	 *
	 * Son los que la tarjeta de salud vigila: si uno deja de correr, algo que la
	 * tienda promete deja de pasar sin ninguna otra señal. Dos de ellos tienen
	 * interruptor en el panel y apagados NO se esperan; esa decisión es del
	 * módulo dueño del interruptor y la aplica `AdminDashboard::watched_crons()`,
	 * no este fichero (que no puede depender de nadie).
	 *
	 * @var array<string, string>
	 */
	public const RECURRING = array(
		'akb_order_preventa_recompute_ready'        => 'Preventa: recompute diario de órdenes listas',            // OrderPreventa\ReadyToShipCron::HOOK
		'akb_order_preventa_t7_reminder_scan'       => 'Preventa: recordatorio previo a la llegada',              // OrderPreventa\RemindersCron::HOOK
		'akb_preventa_vencida_sweep'                => 'Preventa: barrido de lanzamientos con la fecha vencida',  // Stock\PreventaVencidaCron::HOOK
		'akb_bis_cleanup_terminal'                  => 'Lista de espera: limpieza de suscripciones terminales',   // BackInStock\Retention::HOOK
		'akb_taxonomy_discount_expiry_sweep'        => 'Descuentos: apagado automático de la campaña vencida',    // TaxonomyDiscount\CampaignExpiryCron::HOOK
		'akb_upsells_autofill_cron'                 => 'Recomendaciones automáticas: rellenado diario',           // UpsellsAutofill::CRON_HOOK
		'akibara_business_llms_manifest_refresh'    => 'Salud SEO: regeneración diaria de llms.txt',              // LlmsManifest::HOOK
		'akibara_business_social_image_derivatives' => 'Salud SEO: portadas JPG para redes y correos',            // SeoSocial\SocialImageDerivatives::HOOK
		'akb_unpaid_orders_sweep'                   => 'Pedidos: barrido diario de órdenes pendientes sin pago tras 24h', // Stock\UnpaidOrdersSweeper::HOOK
	);

	/**
	 * Eventos de UNA sola vez. No se vigilan —nacen y mueren con su trabajo—,
	 * pero al desactivar o desinstalar puede haber alguno pendiente y hay que
	 * retirarlo igual: un evento huérfano se dispara contra un hook sin callback.
	 *
	 * @var array<int, string>
	 */
	public const SINGLE = array(
		'akibara_business_social_image_derivatives_continue', // SeoSocial\SocialImageDerivatives::HOOK_CONTINUE
		'akibara_business_social_image_for_slug',             //::HOOK_SLUG — lleva el slug como argumento.
		'akibara_business_cleanup_collection_owner_fence',    // SeriesBundles\CustomerCollection — respaldo de Action Scheduler, con argumento.
	);

	/**
	 * Hooks retirados que deben eliminarse de ambas colas del sitio.
	 *
	 * Al retirar un módulo, sus hooks se trasladan desde RECURRING, SINGLE o QUEUE
	 * a esta lista junto a la versión que dejó de agendarlos. Un evento recurrente
	 * puede seguir ejecutándose sin callback hasta retirarlo explícitamente. La
	 * limpieza cubre WP-Cron y Action Scheduler; la tarjeta de salud sólo vigila
	 * los recurrentes vigentes, no exige que un trabajo retirado siga existiendo.
	 *
	 * @var array<string, string>
	 */
	public const RETIRED = array();

	/**
	 * Agrupa los hooks de Action Scheduler propios de este plugin.
	 *
	 * Cada acción usa un grupo del plugin. Sus grupos no incluyen trabajo de otros
	 * plugins ni de mu-plugins, de modo que la desactivación o desinstalación pueda
	 * retirar el conjunto propio sin eliminar acciones ajenas.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const QUEUE = array(
		'akibara-bis'            => array( // BackInStock\StockWatcher::AS_GROUP
			'akb_bis_notify_product',                          // StockWatcher::ASYNC_HOOK
		),
		'akibara-order-preventa' => array( // OrderPreventa\ReadyEmailDispatcher::AS_GROUP
			'akb_order_preventa_dispatch_ready_email',         // ReadyEmailDispatcher::READY_EMAIL_DISPATCH_HOOK
			'akb_order_preventa_recompute_for_product',        // StockChangeListener::ASYNC_HOOK
			'akb_order_preventa_release_date_changed_batch',   // ReleaseDateChangeListener::BATCH_HOOK
			'akb_order_preventa_verify_carrier_handoff',       // CarrierHandoffMonitor::CHECK_HOOK
		),
		'akibara-series-follow'  => array( // SeriesFollow\SeriesFollow::AS_GROUP
			'akb_sf_digest_run',                               // SeriesFollow::HOOK_DIGEST
			'akb_sf_scan_product',                             // SeriesFollow::HOOK_SCAN
		),
		'akb-catalog-order'      => array( // CatalogOrder\CatalogOrder::GROUP
			'akibara_catalog_order_daily',                     // CatalogOrder::DAILY_HOOK
			'akibara_catalog_order_recompute',                 // CatalogOrder::RECOMPUTE_HOOK
		),
		'akb-image-recompress'   => array( // ImageQuality\Recompressor::GROUP
			'akibara_business_image_recompress',               // Recompressor::HOOK
			'akibara_business_image_recompress_purge',         // Recompressor::HOOK_PURGE
		),
		'akb-cache-warm'         => array( // SiteSpeed\CacheWarmer::GROUP
			'akibara_business_cache_warm',                     // CacheWarmer::HOOK
		),
		'akibara-welcome'        => array( // WelcomeCoupon\CouponService::GC_GROUP
			'akb_welcome_coupon_gc_expired',                   // CouponService::GC_HOOK
		),
		'akibara-business'       => array( // El grupo genérico del plugin.
			'akibara_business_cleanup_collection_owner_fence', // SeriesBundles\CustomerCollection — la vía normal; WP-Cron es su respaldo.
			'akibara_edge_purge_run',                          // SiteSpeed\EdgePurge::HOOK
			'akb_bacs_send_reminder_email',                    // BacsLifecycle\BacsLifecycleManager::ACTION_REMINDER
			'akb_bacs_auto_expire_order',                      // BacsLifecycle\BacsLifecycleManager::ACTION_EXPIRE
		),
	);

	/**
	 * Todos los hooks de WP-Cron que hay que retirar: los recurrentes, los de una
	 * vez y los que el plugin ya no agenda pero un sitio puede conservar.
	 *
	 * @return array<int, string>
	 */
	public static function hooks(): array {
		return array_merge( array_keys( self::RECURRING ), self::SINGLE, array_keys( self::RETIRED ) );
	}

	/**
	 * Retira TODO el trabajo en segundo plano del plugin: WP-Cron y la cola.
	 *
	 * WP-Cron, con `wp_unschedule_hook()` para TODOS, no sólo para los que hoy
	 * llevan argumentos: `wp_clear_scheduled_hook( $hook)` sin argumentos retira
	 * únicamente los eventos agendados sin ninguno, así que usarla obliga a
	 * recordar, hook por hook, cuál lleva argumentos —y el día que uno empieza a
	 * llevarlos, deja de retirarse sin que nada falle—. `wp_unschedule_hook()`
	 * se lleva todas las ocurrencias, lleven lo que lleven.
	 *
	 * La cola, por la misma razón, de las DOS formas y sin decidir caso a caso:
	 * por grupo se va también lo que una versión anterior agendó ahí con un hook
	 * que ya no existe; por hook, lo que se agendó bajo otro grupo o sin ninguno.
	 * La copia a mano elegía «por grupo donde el módulo tiene uno, por hook donde
	 * no», y retiraba por hook dos módulos que sí tenían grupo.
	 *
	 * Sin Action Scheduler —WooCommerce inactivo— no hay cola que retirar ni
	 * nadie que la ejecute; al volver, las acciones siguen ahí y los módulos las
	 * atienden.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		foreach ( self::hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::QUEUE as $group => $hooks ) {
			as_unschedule_all_actions( '', array(), $group );
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
		foreach ( array_keys( self::RETIRED ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}
}
