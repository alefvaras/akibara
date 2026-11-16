<?php
/**
 * Matriz Exhaustiva de Certificación Best-Wins y Convivencia de Descuentos en Akibara:
 *
 * Caso 1: Precio normal base puro ($33.980 > $25.000) -> 10% exacto = $3.398.
 * Caso 2: Oferta menor al 10% (5% OFF: $20.000 reg -> $19.000 sale) + tomo normal ($16.990).
 *         Best-Wins completa hasta el 10% en ambos: total descuento = $2.699.
 * Caso 3: Oferta igual al 10% (10% OFF: $20.000 reg -> $18.000 sale) + tomo normal ($16.990).
 *         Cupón descuenta $0 en tomo en oferta y 10% en tomo normal.
 * Caso 4: Oferta superior al 10% (20% OFF: $20.000 reg -> $16.000 sale, 2 unidades).
 *         Bloqueo amigable inmediato. Cupón unburned (usage_count = 0).
 * Caso 5: Carrito mixto (20% OFF + precio normal).
 *         Tomo con 20% no se toca; tomo normal recibe 10%.
 * Caso 6: Carrito bajo el mínimo ($16.990 < $25.000).
 *         Rechazado por compra mínima de WooCommerce.
 * Caso 7: Simulación de pedido real y cálculo de Puntos Akibara Rewards.
 *         Base puntuable es exactamente el dinero neto pagado (0 double-dipping).
 * Caso 8: Preservación de cupón y ciclo de vida tras rechazo vs completado.
 */

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Akibara\Rewards\Points\Calculator;

echo "========================================================================\n";
echo "🧪 MATRIZ EXHAUSTIVA DE CERTIFICACIÓN: BEST-WINS, PRECIO BASE Y REWARDS\n";
echo "========================================================================\n\n";

$coupon_service = new CouponService();
$test_email     = 'qa.bestwins.exhaustivo@gmail.com';

wc_load_cart();
if ( ! isset( WC()->session ) || ! WC()->session ) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
if ( ! isset( WC()->customer ) || ! WC()->customer ) {
    WC()->customer = new WC_Customer();
}
WC()->customer->set_billing_email( $test_email );
WC()->customer->set_email( $test_email );
if ( ! isset( WC()->cart ) || ! WC()->cart ) {
    WC()->cart = new WC_Cart();
}

function reset_stock_and_products() {
    update_post_meta( 30520, '_stock', 10 );
    update_post_meta( 30520, '_stock_status', 'instock' );
    update_post_meta( 30522, '_stock', 10 );
    update_post_meta( 30522, '_stock_status', 'instock' );
    update_post_meta( 30730, '_stock', 10 );
    update_post_meta( 30730, '_stock_status', 'instock' );
    update_post_meta( 30730, '_regular_price', '16990' );
    delete_post_meta( 30730, '_sale_price' );
    update_post_meta( 30730, '_price', '16990' );
    clean_post_cache( 30520 );
    clean_post_cache( 30522 );
    clean_post_cache( 30730 );
}

reset_stock_and_products();

// Emitir cupón único de prueba
$issue = $coupon_service->issue_for_subscriber( 'Tester BestWins', $test_email );
$coupon_code = $issue['coupon_code'];
$coupon_id   = $issue['coupon_id'];
$coupon_obj  = new WC_Coupon( $coupon_code );

echo "Cupón emitido para pruebas: $coupon_code (ID: $coupon_id)\n\n";

$matriz_resultados = array();

try {
    // -------------------------------------------------------------------------
    // CASO 1: Precio normal base ($16.990 + $16.990 = $33.980 > $25.000)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 1: Precio normal base puro\n";
    reset_stock_and_products();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 ); // $16.990
    WC()->cart->add_to_cart( 30522, 1 ); // $16.990
    WC()->cart->calculate_totals();

    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $desc_1 = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $tot_1  = WC()->cart->get_total( 'edit' );
    $pts_1  = Calculator::points_for( Calculator::award_base_for_cart( WC()->cart ) );

    echo "  - Subtotal: $33.980 CLP | Descuento: $" . number_format($desc_1, 0, ',', '.') . " CLP | Total: $" . number_format($tot_1, 0, ',', '.') . " CLP\n";
    echo "  - Puntos Akibara acreditados: $pts_1 pts\n";

    assert( abs( $desc_1 - 3398.0 ) <= 1.0, "Prueba 1 falló: Descuento esperado $3.398, obtenido $desc_1" );
    assert( $pts_1 === 612, "Prueba 1 falló: Puntos esperados 612, obtenidos $pts_1" );
    echo "  ✓ PRUEBA 1 SUPERADA: 10% exacto sobre precio normal base, sin double-dipping.\n\n";
    $matriz_resultados['1_precio_normal'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 2: Oferta menor al 10% (5% OFF en Tomo A: regular $20.000, sale $19.000) + Tomo B ($16.990)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 2: Oferta menor al 10% (5% OFF) -> Best-Wins completa hasta el 10%\n";
    reset_stock_and_products();
    update_post_meta( 30730, '_regular_price', '20000' );
    update_post_meta( 30730, '_sale_price', '19000' ); // 5% OFF (ahorro $1.000)
    update_post_meta( 30730, '_price', '19000' );
    clean_post_cache( 30730 );

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 1 ); // regular $20.000, sale $19.000
    WC()->cart->add_to_cart( 30520, 1 ); // regular $16.990, sale none $16.990
    WC()->cart->calculate_totals();

    $subtotal_2 = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax(); // $19.000 + $16.990 = $35.990
    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $desc_2 = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $tot_2  = WC()->cart->get_total( 'edit' );
    $pts_2  = Calculator::points_for( Calculator::award_base_for_cart( WC()->cart ) );

    echo "  - Subtotal carrito: $" . number_format($subtotal_2, 0, ',', '.') . " CLP\n";
    echo "  - Descuento del cupón (complemento): $" . number_format($desc_2, 0, ',', '.') . " CLP (Esperado: $2.699 CLP)\n";
    echo "  - Total pagado: $" . number_format($tot_2, 0, ',', '.') . " CLP (Esperado: $33.291 CLP)\n";
    echo "  - Puntos acreditados: $pts_2 pts (Esperado: 666 pts sobre $33.291)\n";

    assert( abs( $desc_2 - 2699.0 ) <= 1.0, "Prueba 2 falló: Descuento esperado $2.699, obtenido $desc_2" );
    assert( abs( $tot_2 - 33291.0 ) <= 1.0, "Prueba 2 falló: Total pagado esperado $33.291, obtenido $tot_2" );
    assert( $pts_2 === 666, "Prueba 2 falló: Puntos esperados 666, obtenidos $pts_2" );
    echo "  ✓ PRUEBA 2 SUPERADA: Best-Wins completó con exactitud matemática el 5% hasta el 10% del precio normal.\n\n";
    $matriz_resultados['2_complemento_5pct'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 3: Oferta igual al 10% (10% OFF en Tomo A: $20.000 -> $18.000) + Tomo B ($16.990)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 3: Oferta igual al 10% (10% OFF) en Tomo A + Tomo B a precio normal\n";
    reset_stock_and_products();
    update_post_meta( 30730, '_regular_price', '20000' );
    update_post_meta( 30730, '_sale_price', '18000' ); // 10% OFF exacto
    update_post_meta( 30730, '_price', '18000' );
    clean_post_cache( 30730 );

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 1 ); // regular $20.000, sale $18.000 (10% OFF)
    WC()->cart->add_to_cart( 30520, 1 ); // regular $16.990 (0% OFF)
    WC()->cart->calculate_totals();

    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $desc_3 = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $tot_3  = WC()->cart->get_total( 'edit' );

    echo "  - Descuento del cupón: $" . number_format($desc_3, 0, ',', '.') . " CLP (Esperado: $1.699 CLP)\n";
    echo "  - Total pagado: $" . number_format($tot_3, 0, ',', '.') . " CLP (Esperado: $33.291 CLP)\n";

    assert( abs( $desc_3 - 1699.0 ) <= 1.0, "Prueba 3 falló: Descuento esperado $1.699, obtenido $desc_3" );
    echo "  ✓ PRUEBA 3 SUPERADA: Tomo con 10% OFF aporta $0 al cupón; tomo normal descuenta su 10% íntegro.\n\n";
    $matriz_resultados['3_oferta_igual_10pct'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 4: Oferta superior al 10% en TODOS los productos (20% OFF: 2 unidades de $20.000 -> $16.000)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 4: Carrito donde todos los productos superan el 10% (20% OFF)\n";
    reset_stock_and_products();
    update_post_meta( 30730, '_regular_price', '20000' );
    update_post_meta( 30730, '_sale_price', '16000' ); // 20% OFF
    update_post_meta( 30730, '_price', '16000' );
    clean_post_cache( 30730 );

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 2 ); // $16.000 x 2 = $32.000 (> $25.000 mín)
    WC()->cart->calculate_totals();

    $bloqueo_ocurrido = false;
    $msg_bloqueo      = '';
    try {
        $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    } catch ( Exception $e ) {
        $bloqueo_ocurrido = true;
        $msg_bloqueo      = $e->getMessage();
    }

    echo "  - Bloqueo amigable activo: " . ( $bloqueo_ocurrido ? 'SÍ' : 'NO' ) . "\n";
    echo "  - Mensaje: \"$msg_bloqueo\"\n";

    assert( $bloqueo_ocurrido === true, "Prueba 4 falló: El cupón debió bloquearse." );
    assert( strpos( $msg_bloqueo, 'Mantenemos tu oferta actual y tu código sigue disponible' ) !== false, "Mensaje no concuerda." );

    $usage_4 = (int) get_post_meta( $coupon_id, 'usage_count', true );
    assert( $usage_4 === 0, "Prueba 4 falló: usage_count debió ser 0." );
    echo "  ✓ PRUEBA 4 SUPERADA: Bloqueo transparente y preservación del cupón no quemado en BD.\n\n";
    $matriz_resultados['4_bloqueo_oferta_superior'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 5: Carrito mixto (20% OFF en Tomo A + Tomo B a precio normal)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 5: Carrito mixto (20% OFF en Tomo A + Tomo B precio normal)\n";
    reset_stock_and_products();
    update_post_meta( 30730, '_regular_price', '20000' );
    update_post_meta( 30730, '_sale_price', '16000' ); // 20% OFF
    update_post_meta( 30730, '_price', '16000' );
    clean_post_cache( 30730 );

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 1 ); // regular $20.000, sale $16.000 (20% off)
    WC()->cart->add_to_cart( 30520, 1 ); // regular $16.990, sale none $16.990
    WC()->cart->calculate_totals();

    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $desc_5 = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $tot_5  = WC()->cart->get_total( 'edit' );

    echo "  - Descuento aplicado en mixto: $" . number_format($desc_5, 0, ',', '.') . " CLP\n";
    echo "  - Total pagado: $" . number_format($tot_5, 0, ',', '.') . " CLP\n";

    assert( abs( $desc_5 - 1699.0 ) <= 1.0, "Prueba 5 falló: Descuento esperado $1.699, obtenido $desc_5" );
    echo "  ✓ PRUEBA 5 SUPERADA: Best-Wins no compone descuentos; aplica solo a la porción elegible.\n\n";
    $matriz_resultados['5_carrito_mixto'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 6: Carrito bajo el mínimo ($16.990 < $25.000)
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 6: Validación de compra mínima ($25.000 CLP)\n";
    reset_stock_and_products();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 ); // $16.990 (< $25.000)
    WC()->cart->calculate_totals();

    $subtotal_6 = (float) WC()->cart->get_displayed_subtotal();
    $min_req    = (float) $coupon_obj->get_minimum_amount();
    $pudo_aplicar = WC()->cart->apply_coupon( $coupon_code );

    echo "  - Subtotal: $" . number_format($subtotal_6, 0, ',', '.') . " CLP | Compra mínima: $" . number_format($min_req, 0, ',', '.') . " CLP\n";
    echo "  - ¿WooCommerce aceptó el cupón?: " . ( $pudo_aplicar ? 'SÍ (Error)' : 'NO (Rechazado correctamente)' ) . "\n";

    assert( $pudo_aplicar === false, "Prueba 6 falló: El cupón debió ser rechazado por monto mínimo." );
    echo "  ✓ PRUEBA 6 SUPERADA: Compra mínima de $25.000 protegida por WooCommerce.\n\n";
    $matriz_resultados['6_compra_minima'] = 'PASS';

    // -------------------------------------------------------------------------
    // CASO 7: Simulación de Pedido Real y Acreditación de Puntos en Pedido
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 7: Creación de Pedido Real y base de Puntos en Orden (HPOS)\n";
    reset_stock_and_products();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 ); // $16.990
    WC()->cart->add_to_cart( 30522, 1 ); // $16.990
    WC()->cart->calculate_totals();
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $order = wc_create_order( array(
        'customer_id'   => 0,
        'billing_email' => $test_email,
    ) );
    foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
        $order->add_product( $values['data'], $values['quantity'], array(
            'subtotal' => $values['line_subtotal'],
            'total'    => $values['line_total'],
        ) );
    }
    $coupon_item = new \WC_Order_Item_Coupon();
    $coupon_item->set_code( $coupon_code );
    $coupon_item->set_discount( 3398 );
    $order->add_item( $coupon_item );
    $order->calculate_totals();
    $order->save();

    $order_id      = $order->get_id();
    $order_total   = (float) $order->get_total();
    $order_discount= (float) $order->get_discount_total();
    $order_base_pts= Calculator::award_base_for_order( $order );
    $order_pts     = Calculator::points_for( $order_base_pts );

    echo "  - Pedido de prueba #$order_id creado:\n";
    echo "    Total pedido: $" . number_format($order_total, 0, ',', '.') . " CLP\n";
    echo "    Descuento neto de cupón en pedido: $" . number_format($order_discount, 0, ',', '.') . " CLP\n";
    echo "    Base de cálculo en Calculator::award_base_for_order: $" . number_format($order_base_pts, 0, ',', '.') . " CLP\n";
    echo "    Puntos finales de la orden: $order_pts pts\n";

    assert( abs( $order_total - 30582.0 ) <= 1.0, "Total de orden incorrecto." );
    assert( abs( $order_base_pts - 30582.0 ) <= 1.0, "Base puntuable en orden incorrecta." );
    assert( $order_pts === 612, "Puntos de orden incorrectos." );

    // Transicionar a completado para verificar consumo de cupón
    $order->set_status( 'processing' );
    $order->save();
    $coupon_obj->increase_usage_count( $order->get_billing_email() );
    $coupon_service->mark_order_coupon_used( $order_id );

    $usage_final = (int) get_post_meta( $coupon_id, 'usage_count', true );
    $notified    = (string) get_post_meta( $coupon_id, '_akb_welcome_used_notified', true );
    $order_saved = (string) get_post_meta( $coupon_id, '_akb_welcome_used_order_id', true );

    echo "  - Conteo de uso tras compra confirmada: $usage_final uso(s)\n";
    echo "  - Meta _akb_welcome_used_notified: $notified | _akb_welcome_used_order_id: $order_saved\n";
    assert( $usage_final >= 1, "El cupón debió marcarse como usado tras pedido completado." );
    assert( $notified === '1', "El meta _akb_welcome_used_notified debió ser 1." );
    assert( $order_saved === (string) $order_id, "El ID de orden guardado no coincide." );

    echo "  ✓ PRUEBA 7 SUPERADA: Pedido real calculó neto exacto y consumió el cupón legítimamente.\n\n";
    $matriz_resultados['7_orden_y_consumo'] = 'PASS';

    // Limpieza de pedido de prueba
    wp_delete_post( $order_id, true );
    echo "  - Pedido de prueba #$order_id eliminado de la BD local.\n\n";

    // -------------------------------------------------------------------------
    // CASO 8: Transbank / Método de Pago falla o Transferencia se Cancela
    // Des-quema automático: el cliente NO pierde su cupón y puede volver a ocuparlo.
    // -------------------------------------------------------------------------
    echo "▶ PRUEBA 8: Falla de Webpay / Cancelación de Transferencia (Des-quema automático)\n";
    $issue_8 = $coupon_service->issue_for_subscriber( 'Tester Falla', 'tester.falla@akibara.cl' );
    $code_8  = $issue_8['coupon_code'];
    $id_8    = $issue_8['coupon_id'];
    $obj_8   = new WC_Coupon( $code_8 );

    reset_stock_and_products();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 );
    WC()->cart->add_to_cart( 30522, 1 );
    WC()->cart->calculate_totals();

    $order_8 = wc_create_order( array(
        'customer_id'   => 0,
        'billing_email' => 'tester.falla@akibara.cl',
    ) );
    foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
        $order_8->add_product( $values['data'], $values['quantity'], array(
            'subtotal' => $values['line_subtotal'],
            'total'    => $values['line_total'],
        ) );
    }
    $c_item_8 = new \WC_Order_Item_Coupon();
    $c_item_8->set_code( $code_8 );
    $c_item_8->set_discount( 3398 );
    $order_8->add_item( $c_item_8 );
    $order_8->calculate_totals();
    $order_8->save();

    $order_8_id = $order_8->get_id();

    // Simular que el pedido había entrado a processing (o on-hold) y se marcó usado
    $obj_8->increase_usage_count( 'tester.falla@akibara.cl' );
    $coupon_service->mark_order_coupon_used( $order_8_id );

    $usage_before_cancel = (int) get_post_meta( $id_8, 'usage_count', true );
    echo "  - Estado inicial: Pedido #$order_8_id con cupón usado ($usage_before_cancel uso)\n";

    // Ocurre rechazo de Webpay o cliente cancela transferencia
    echo "  - Simulando rechazo de pasarela / cancelación de orden...\n";
    $order_8->set_status( 'cancelled' );
    $order_8->save();
    $coupon_service->restore_order_coupon( $order_8_id );

    $usage_after_cancel  = (int) get_post_meta( $id_8, 'usage_count', true );
    $notified_after      = (string) get_post_meta( $id_8, '_akb_welcome_used_notified', true );
    $order_after         = (string) get_post_meta( $id_8, '_akb_welcome_used_order_id', true );

    echo "  - Estado tras cancelación:\n";
    echo "    usage_count: $usage_after_cancel (Esperado: 0)\n";
    echo "    _akb_welcome_used_notified: \"$notified_after\" (Esperado: vacío)\n";
    echo "    _akb_welcome_used_order_id: \"$order_after\" (Esperado: vacío)\n";

    assert( $usage_after_cancel === 0, "Prueba 8 falló: usage_count debió volver a 0." );
    assert( $notified_after === '', "Prueba 8 falló: _akb_welcome_used_notified debió ser borrado." );
    assert( $order_after === '', "Prueba 8 falló: _akb_welcome_used_order_id debió ser borrado." );

    // Verificar que el cliente AHORA SÍ puede re-aplicar su cupón en un nuevo carrito
    WC()->customer->set_billing_email( 'tester.falla@akibara.cl' );
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 );
    WC()->cart->add_to_cart( 30522, 1 );
    WC()->cart->calculate_totals();

    $reaplicado_ok = WC()->cart->apply_coupon( $code_8 );
    echo "  - ¿El cliente pudo volver a aplicar su cupón en un nuevo intento?: " . ( $reaplicado_ok ? 'SÍ (100% Recuperado)' : 'NO (Fallo)' ) . "\n";
    assert( $reaplicado_ok === true, "Prueba 8 falló: El cliente debió poder re-aplicar su cupón." );

    echo "  ✓ PRUEBA 8 SUPERADA: Cupón restaurado con éxito ante falla de pago; cliente no pierde beneficio.\n\n";
    $matriz_resultados['8_restauracion_falla_pago'] = 'PASS';

    wp_delete_post( $order_8_id, true );
    wp_delete_post( $id_8, true );

} finally {
    reset_stock_and_products();
    wp_delete_post( $coupon_id, true );
    WC()->cart->empty_cart();
    echo "✓ Entorno de pruebas restaurado al estado original.\n";
}

echo "========================================================================\n";
echo "📊 RESUMEN FINAL DE LA MATRIZ DE CERTIFICACIÓN BEST-WINS:\n";
echo "========================================================================\n";
foreach ( $matriz_resultados as $test_name => $res ) {
    echo "  [$res] $test_name\n";
}
echo "\n🎉 TODAS LAS PRUEBAS DE LA MATRIZ CUMPLEN AL 100% CON LA ESPECIFICACIÓN.\n";
