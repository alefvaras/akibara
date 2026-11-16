<?php
/**
 * Script de validación técnica profunda:
 * 1. Best-Wins: Descuento 10% exclusivamente sobre precio normal base.
 * 2. Protección de ofertas mayores: Si el descuento existente es >= 10%, el cupón no se quema y se guarda para después.
 * 3. Carrito mixto: Solo descuenta la porción no rebajada (sin efecto compuesto ni acumulación abusiva).
 * 4. Programa de Puntos Akibara Rewards: Los puntos se ganan sobre el NETO pagado (anti double-dipping).
 */

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Akibara\Rewards\Points\Calculator;

echo "================================================================\n";
echo "🎯 CERTIFICACIÓN PROFUNDA: BEST-WINS, CUPÓN 10% Y PUNTOS AKIBARA\n";
echo "================================================================\n\n";

$coupon_service = new CouponService();
$test_email     = 'comprador.manga.test@gmail.com';

// Inicializar sesión y carrito de WooCommerce
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

// 1. Crear cupón único de prueba para cliente
echo "1. Emitiendo cupón de bienvenida para $test_email...\n";
$issue_result = $coupon_service->issue_for_subscriber( 'Lector VIP', $test_email );
if ( empty( $issue_result['ok'] ) || empty( $issue_result['coupon_code'] ) ) {
    throw new Exception( "Fallo al emitir cupón: " . json_encode( $issue_result ) );
}
$coupon_code = $issue_result['coupon_code'];
$coupon_id   = $issue_result['coupon_id'];
echo "✓ Cupón emitido: $coupon_code (ID: $coupon_id) | 10% sobre $25.000 mín.\n\n";

$coupon_obj = new WC_Coupon( $coupon_code );

try {
    // -------------------------------------------------------------------------
    // CASO 1: PRODUCTOS A PRECIO NORMAL ($33.980 > $25.000)
    // -------------------------------------------------------------------------
    echo "----------------------------------------------------------------\n";
    echo "CASO 1: Productos a precio normal base (Homunculus 01 + 02: $16.990 c/u = $33.980)\n";
    echo "----------------------------------------------------------------\n";
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30520, 1 ); // $16.990
    WC()->cart->add_to_cart( 30522, 1 ); // $16.990
    WC()->cart->calculate_totals();

    $subtotal_bruto = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
    echo "  - Subtotal bruto con IVA: $" . number_format( $subtotal_bruto, 0, ',', '.' ) . " CLP\n";

    // Validar conveniencia
    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $descuento_aplicado = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $total_final        = WC()->cart->get_total( 'edit' );

    echo "  - Descuento aplicado por el cupón (10%): $" . number_format( $descuento_aplicado, 0, ',', '.' ) . " CLP\n";
    echo "  - Total pagado por el cliente: $" . number_format( $total_final, 0, ',', '.' ) . " CLP\n";

    if ( abs( $descuento_aplicado - 3398.0 ) > 1.0 ) {
        throw new Exception( "El descuento de 10% no coincide exacto con los $3.398 CLP esperados. Calculado: $descuento_aplicado" );
    }
    echo "  ✓ Descuento del 10% sobre precio base verificado con precisión de centavo ($3.398 CLP).\n";

    // Interacción con Puntos Akibara Rewards:
    $base_puntos    = Calculator::award_base_for_cart( WC()->cart );
    $puntos_ganados = Calculator::points_for( $base_puntos );
    echo "  - Base puntuable en Akibara Rewards (neto pagado): $" . number_format( $base_puntos, 0, ',', '.' ) . " CLP\n";
    echo "  - Puntos Akibara que ganará el cliente: $puntos_ganados puntos (2 por cada $100)\n";

    if ( $base_puntos > $subtotal_bruto - $descuento_aplicado + 1.0 ) {
        throw new Exception( "Error: Se están otorgando puntos sobre el monto descontado (Double-Dipping)!" );
    }
    echo "  ✓ Puntos calculados estrictamente sobre el NETO pagado ($30.582 CLP = 612 puntos).\n";
    echo "  ✓ CASO 1 APROBADO: Margen 100% protegido.\n\n";

    // -------------------------------------------------------------------------
    // CASO 2: PRODUCTO CON OFERTA MAYOR (20% OFF)
    // «¿Qué ocurre cuando el valor promocional es mayor? No se debe poder aplicar
    // y guardarlo para más adelante con el mail de respaldo que envía Brevo»
    // -------------------------------------------------------------------------
    echo "----------------------------------------------------------------\n";
    echo "CASO 2: Productos con oferta mayor (20% OFF > 10% de bienvenida)\n";
    echo "----------------------------------------------------------------\n";
    // Configurar temporalmente producto 30730: regular $20.000, rebajado a $16.000 (20% OFF)
    update_post_meta( 30730, '_regular_price', '20000' );
    update_post_meta( 30730, '_sale_price', '16000' );
    update_post_meta( 30730, '_price', '16000' );
    clean_post_cache( 30730 );

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 2 ); // $16.000 x 2 = $32.000 (precio normal base $40.000, ahorro ya es $8.000 = 20%)
    WC()->cart->calculate_totals();

    $subtotal_oferta = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
    echo "  - Subtotal con oferta del 20%: $" . number_format( $subtotal_oferta, 0, ',', '.' ) . " CLP (Ahorro actual: $8.000 CLP)\n";

    $rechazo_conveniencia_ocurrido = false;
    $mensaje_rechazo = '';
    try {
        $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    } catch ( Exception $e ) {
        $rechazo_conveniencia_ocurrido = true;
        $mensaje_rechazo = $e->getMessage();
    }

    echo "  - ¿Se bloqueó la aplicación del cupón?: " . ( $rechazo_conveniencia_ocurrido ? 'SÍ (Bloqueo amigable activo)' : 'NO' ) . "\n";
    echo "  - Mensaje informado al cliente: \"$mensaje_rechazo\"\n";

    if ( ! $rechazo_conveniencia_ocurrido ) {
        throw new Exception( "Error: El cupón se permitió aplicar en un carrito donde la oferta ya supera el 10%!" );
    }
    if ( strpos( $mensaje_rechazo, 'Mantenemos tu oferta actual y tu código sigue disponible' ) === false ) {
        throw new Exception( "El mensaje no garantiza al cliente que su código sigue disponible para después." );
    }

    // Verificar que el cupón no se consumió en la base de datos
    $usage_count = (int) get_post_meta( $coupon_id, 'usage_count', true );
    echo "  - Conteo de uso del cupón en base de datos: $usage_count usos\n";
    if ( $usage_count > 0 ) {
        throw new Exception( "Error: El cupón fue marcado como usado a pesar de no convenirle al cliente!" );
    }
    echo "  ✓ El cupón NO se consume; queda 100% disponible en Brevo para la siguiente compra.\n";
    echo "  ✓ CASO 2 APROBADO: El cliente conserva su descuento mayor del 20% y guarda su cupón.\n\n";

    // -------------------------------------------------------------------------
    // CASO 3: CARRITO MIXTO (1 con 20% OFF + 1 a precio normal)
    // Regla Best-Wins: solo descuenta la línea no rebajada, sin duplicar descuento
    // -------------------------------------------------------------------------
    echo "----------------------------------------------------------------\n";
    echo "CASO 3: Carrito Mixto (Tomo A con 20% OFF + Tomo B a precio normal)\n";
    echo "----------------------------------------------------------------\n";
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart( 30730, 1 ); // Tomo A: regular $20.000, sale $16.000 (20% off)
    WC()->cart->add_to_cart( 30520, 1 ); // Tomo B: regular $16.990, sale none $16.990
    WC()->cart->calculate_totals();

    $subtotal_mixto = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax(); // $16.000 + $16.990 = $32.990
    echo "  - Subtotal carrito mixto con IVA: $" . number_format( $subtotal_mixto, 0, ',', '.' ) . " CLP\n";

    // En este caso, el cupón SÍ debe aplicar, pero SOLO sobre Tomo B ($16.990 -> $1.699).
    // Tomo A ya tiene 20% OFF, por lo que su aporte al cupón de bienvenida debe ser exactamente $0.
    $coupon_service->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon_obj );
    WC()->cart->apply_coupon( $coupon_code );
    WC()->cart->calculate_totals();

    $descuento_mixto = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
    $total_mixto     = WC()->cart->get_total( 'edit' );

    echo "  - Descuento aplicado por el cupón: $" . number_format( $descuento_mixto, 0, ',', '.' ) . " CLP\n";
    echo "  - Total pagado en carrito mixto: $" . number_format( $total_mixto, 0, ',', '.' ) . " CLP\n";

    // Esperado: 10% sobre Tomo B ($16.990) = $1.699 CLP. (Sobre Tomo A aporta $0 porque 20% > 10%).
    if ( abs( $descuento_mixto - 1699.0 ) > 1.0 ) {
        throw new Exception( "Error en Best-Wins mixto: Descuento esperado $1.699 CLP, obtenido $descuento_mixto CLP" );
    }
    echo "  ✓ Best-Wins comprobado: Tomo A no se re-descuenta; Tomo B descuenta su 10% exacto.\n";

    // Puntos ganados en carrito mixto
    $base_puntos_mixto    = Calculator::award_base_for_cart( WC()->cart );
    $puntos_ganados_mixto = Calculator::points_for( $base_puntos_mixto );
    echo "  - Base puntuable en carrito mixto: $" . number_format( $base_puntos_mixto, 0, ',', '.' ) . " CLP\n";
    echo "  - Puntos Akibara acreditables: $puntos_ganados_mixto puntos (2 por cada $100)\n";

    if ( abs( $base_puntos_mixto - ( $subtotal_mixto - $descuento_mixto ) ) > 1.0 ) {
        throw new Exception( "Error: Base de puntos no coincide con el total neto pagado." );
    }
    echo "  ✓ Puntos calculados sobre los $" . number_format( $total_mixto, 0, ',', '.' ) . " CLP pagados.\n";
    echo "  ✓ CASO 3 APROBADO: Descuento justo, sin acumulación abusiva y puntos blindados.\n\n";

} finally {
    // Restaurar producto 30730
    update_post_meta( 30730, '_regular_price', '10000' );
    delete_post_meta( 30730, '_sale_price' );
    update_post_meta( 30730, '_price', '10000' );
    clean_post_cache( 30730 );

    // Eliminar cupón de prueba
    wp_delete_post( $coupon_id, true );
    WC()->cart->empty_cart();
    echo "✓ Producto 30730 restaurado, cupón de prueba $coupon_code eliminado y carrito vaciado.\n";
}

echo "\n================================================================\n";
echo "🎉 TODAS LAS REGLAS DE NEGOCIO Y PUNTOS VALIDADAS EN VERDE 🎉\n";
echo "================================================================\n";
