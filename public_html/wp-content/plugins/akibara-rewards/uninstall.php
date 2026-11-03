<?php
/**
 * Uninstall — NO destructivo por diseño.
 *
 * El ledger es historia de dinero: borrar el plugin desde wp-admin no puede
 * llevarse los saldos de los clientes (la lección del uninstall destructivo
 * de terceros está documentada en el proyecto). Para una purga real y
 * consciente, definir AKIBARA_REWARDS_ALLOW_WIPE en wp-config.php antes de
 * desinstalar.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// TODAS las acciones propias se retiran de Action Scheduler SIEMPRE, con o sin
// purga: sin el plugin no queda oyente y cada corrida fallaría en la cola para
// siempre (hallazgo de QA akibara-3f, 2026-09-12). Hasta 0.4.6 se retiraban
// sólo tres de nueve, y las que faltaban son justo las que pueden estar
// PENDIENTES en el momento de desinstalar —un lote de vencimiento a medias, un
// aviso encolado, un correo esperando su reintento— (hallazgo S-963bf905).
// Hooks como literales a propósito: aquí no hay autoloader. La prueba
// `LaColaQuedaLimpiaAlDesinstalarTest` falla si nace un hook y no se suma acá.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'akb_rewards_expiry_daily' );      // Points\Expiry::HOOK
	as_unschedule_all_actions( 'akb_rewards_expiry_batch' );      // Points\Expiry::HOOK_BATCH
	as_unschedule_all_actions( 'akb_rewards_expiry_warn_async' ); // Points\Expiry::HOOK_WARN_ASYNC
	as_unschedule_all_actions( 'akb_rewards_birthday_daily' );    // Points\Birthday::HOOK
	as_unschedule_all_actions( 'akb_rewards_redemption_retry' );  // Points\Redemption::HOOK_RETRY
	as_unschedule_all_actions( 'akb_rewards_accrual_sweep_daily' ); // Points\Accrual::HOOK_SWEEP
	as_unschedule_all_actions( 'akb_rewards_send_review_invite' ); // Reviews\Invitations::HOOK
	as_unschedule_all_actions( 'akb_rewards_order_email_retry' );  // Points\Emails::HOOK_RETRY_ORDER
	as_unschedule_all_actions( 'akb_rewards_review_email_retry' ); // Points\Emails::HOOK_RETRY_REVIEW
	as_unschedule_all_actions( 'akb_rewards_referral_email_retry' ); // Points\Emails::HOOK_RETRY_REFERRAL
	as_unschedule_all_actions( 'akb_rewards_review_tokens_cleanup' ); // Reviews\TokenCleanup::HOOK
	as_unschedule_all_actions( 'akb_rewards_home_cache_retry' ); // Reviews\HomeCache::HOOK
}

if ( ! defined( 'AKIBARA_REWARDS_ALLOW_WIPE' ) || ! AKIBARA_REWARDS_ALLOW_WIPE ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- purga explícita y consentida.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}akb_rewards_ledger" );
// La tabla de enlaces de reseña es tan propia como el ledger y se quedaba
// viva tras una purga consciente (hallazgo S-64da5c8d).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}akb_rewards_review_forms" );
// Por PREFIJO y no por lista: la enumeración de tres claves dejaba atrás ocho
// —cumpleaños, avisos de vencimiento, códigos de padrino, sellos de premio— y
// cualquier meta nueva habría nacido fuera de la purga (S-64da5c8d). Las metas
// `_akb_rewards_*` de PEDIDO no se tocan: viven en las tablas de WooCommerce y
// son parte del registro del pedido, no del cliente.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_akb\\_rewards\\_%'" );
// La lista de bajas de las invitaciones se queda: son huellas SHA-256 sin
// identidad, no se pueden reconstruir desde nada que quede en el sitio, y es el
// único estado del plugin que existe para proteger al cliente — perderla haría
// que una reinstalación volviera a escribirle a quien pidió que no.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'akb\\_rewards\\_%' AND option_name <> 'akb_rewards_invite_optout'" );
// phpcs:enable
