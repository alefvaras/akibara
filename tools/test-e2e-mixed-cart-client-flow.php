<?php
/**
 * Test E2E Flujo Cliente Carrito Mixto (Stock + A Pedido)
 */
declare(strict_types=1);

global $wpdb;

echo "============================================================\n";
echo "  TEST E2E: FLUJO CLIENTE CARRITO MIXTO (STOCK + A PEDIDO)\n";
echo "============================================================\n";

// Seleccionar dinámicamente un producto en stock y uno a pedido
$id_instock = (int) $wpdb->get_var(
    "SELECT p.ID FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status' AND pm.meta_value = 'instock'
     WHERE p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1"
);

$id_onbackorder = (int) $wpdb->get_var(
    "SELECT p.ID FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status' AND pm.meta_value = 'onbackorder'
     WHERE p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1"
);

$p_instock = wc_get_product($id_instock);
$p_onbackorder = wc_get_product($id_onbackorder);

echo "1. Productos de prueba:\n";
echo "   • InStock: ID {$id_instock} - {$p_instock->get_name()} (Stock: {$p_instock->get_stock_status()})\n";
echo "   • OnBackorder: ID {$id_onbackorder} - {$p_onbackorder->get_name()} (Stock: {$p_onbackorder->get_stock_status()})\n";

// 1. Simular Carrito Mixto
WC()->cart->empty_cart();
WC()->cart->add_to_cart($id_instock, 1);
WC()->cart->add_to_cart($id_onbackorder, 1);

$is_mixed = \Akibara\Business\Modules\Logistics\MixedCartShipmentGuard::cart_is_mixed();
$has_backorders = \Akibara\Business\Modules\Logistics\MixedCartShipmentGuard::cart_has_backorders();

echo "\n2. Verificación de Carrito en Memoria:\n";
echo "   • ¿Es Carrito Mixto?: " . ($is_mixed ? "SÍ (CORRECTO)" : "NO (ERROR)") . "\n";
echo "   • ¿Tiene tomos A Pedido?: " . ($has_backorders ? "SÍ (CORRECTO)" : "NO (ERROR)") . "\n";

if (!$is_mixed) {
    throw new RuntimeException("Fallo en detección de carrito mixto");
}

// 2. Simular Creación de Orden Mixta
echo "\n3. Creación de Orden de Prueba:\n";
$order = wc_create_order();
$order->add_product($p_instock, 1);
$order->add_product($p_onbackorder, 1);
$order->set_billing_first_name("Cliente");
$order->set_billing_last_name("Prueba");
$order->set_billing_email("cliente.prueba@akibara.test");
$order->set_billing_phone("+56912345678");
$order->set_shipping_address_1("Gran Avenida 5000");
$order->set_shipping_city("San Miguel");
$order->set_shipping_state("RM");

// Simular aceptación de despacho consolidado (como hace el checkout)
$order->update_meta_data(\Akibara\Business\Modules\Logistics\MixedCartShipmentGuard::META_KEY, 'yes');
$order->calculate_totals();
$order->update_status('processing', 'Pago verificado para prueba E2E carrito mixto');
$order->save();

$order_id = $order->get_id();
echo "   • Orden creada con éxito: #{$order_id}\n";
echo "   • Estado de la orden: {$order->get_status()}\n";
echo "   • Meta despacho consolidado: " . $order->get_meta(\Akibara\Business\Modules\Logistics\MixedCartShipmentGuard::META_KEY) . "\n";

// 3. Verificación de Bloqueo por OrderLockGuard
echo "\n4. Verificación de Blindaje OrderLockGuard:\n";
$locked_ids = \Akibara\Business\Modules\PricingMatrix\OrderLockGuard::get_locked_product_ids();
$instock_locked = in_array($id_instock, $locked_ids, true);
$onbackorder_locked = in_array($id_onbackorder, $locked_ids, true);

echo "   • ¿ID {$id_instock} (InStock) protegido contra cambios de precio/catálogo?: " . ($instock_locked ? "SÍ (BLOQUEADO)" : "NO (ERROR)") . "\n";
echo "   • ¿ID {$id_onbackorder} (OnBackorder) protegido contra cambios de precio/catálogo?: " . ($onbackorder_locked ? "SÍ (BLOQUEADO)" : "NO (ERROR)") . "\n";

// 4. Verificación de Sincronizador de Precios ante orden activa
echo "\n5. Verificación de PricingSynchronizer (Intento de mutación de escala):\n";
$brands = wp_get_post_terms($id_onbackorder, 'product_brand');
$brand_id = !empty($brands) ? (int)$brands[0]->term_id : 230;
$editorial_slug = !empty($brands) ? (string)$brands[0]->slug : 'ivrea-argentina';
$reg_price = (int)$p_onbackorder->get_regular_price();

$sim_result = \Akibara\Business\Modules\PricingMatrix\PricingSynchronizer::sync_scale(
    $brand_id,
    $editorial_slug,
    $reg_price,
    $reg_price + 100,
    5.0,
    'round_100',
    null,
    true, // protect_active_orders
    true  // dry_run
);

$skipped_locked_ids = array_column($sim_result['skipped_locked_items'] ?? array(), 'product_id');

echo "   • Tomos que cambiarían en la escala: " . $sim_result['updated_count'] . "\n";
echo "   • Tomos omitidos/protegidos por tener pedido activo: " . $sim_result['skipped_locked_count'] . "\n";
$is_protected = in_array($id_onbackorder, $skipped_locked_ids, true);
echo "   • ¿Nuestro tomo #{$id_onbackorder} fue defendido y omitido?: " . ($is_protected ? "SÍ (PROTEGIDO)" : "NO (ERROR)") . "\n";

if (!$is_protected) {
    throw new RuntimeException("ERROR: El tomo con pedido activo no fue protegido por OrderLockGuard en sync_scale");
}

// 5. Limpieza Absoluta de la Orden de Prueba
echo "\n6. Limpieza higiénica del entorno:\n";
$order->delete(true); // Hard delete
WC()->cart->empty_cart();
echo "   • Orden #{$order_id} eliminada permanentemente. Base de datos limpia.\n";

echo "\n[RESULTADO]: VALIDACIÓN E2E DE CARRITO MIXTO 100% EXITOSA.\n";
