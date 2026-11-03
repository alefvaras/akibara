<?php
/**
 * Retira exclusivamente las opciones de observabilidad y aceleración
 * propias de Checkout Blocks: conteos de scripts y registro PUDO.
 * El prefijo compartido akb_ no habilita borrar opciones de otros plugins.
 * Se conservan pedidos, direcciones y puntos de retiro almacenados en ellos.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// El guion bajo se escapa (\_) porque en LIKE es comodín de un carácter.
//
// FRONTERA DE NAMESPACE: sólo lo propio. `akb_` a secas es de
// akibara-business, `akb_search_*` del buscador y `akb_rewards_*` de puntos;
// este barrido nombra SUS DOS prefijos y nada más.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	  WHERE option_name LIKE 'akb\\_blocks\\_%'
	     OR option_name LIKE '\\_transient\\_akb\\_blocks\\_%'
	     OR option_name LIKE '\\_transient\\_timeout\\_akb\\_blocks\\_%'
	     OR option_name LIKE '\\_transient\\_akb\\_pudo\\_log\\_%'
	     OR option_name LIKE '\\_transient\\_timeout\\_akb\\_pudo\\_log\\_%'"
);
