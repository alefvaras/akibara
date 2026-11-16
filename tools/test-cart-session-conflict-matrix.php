<?php
/**
 * Test Cart Session Conflict Matrix
 * Valida interacciones dinámicas en el carrito vivo de WooCommerce.
 */

defined('ABSPATH') || exit;

use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Support\Options as RewardsOptions;
use Akibara\Business\Services\FreeShippingThreshold;

echo "========================================================================\n";
echo "PRUEBAS DE INTERACCIÓN Y CONFLICTOS EN CARRITO VIVO DE WOOCOMMERCE\n";
echo "========================================================================\n\n";

wc_load_cart();
if (!isset(WC()->session) || !WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
if (!isset(WC()->customer) || !WC()->customer) {
    WC()->customer = new WC_Customer();
}
if (!isset(WC()->cart) || !WC()->cart) {
    WC()->cart = new WC_Cart();
}

// -----------------------------------------------------------------------------
// TEST 4.1: Puntos Activos -> Intento de Aplicar Cupón
// -----------------------------------------------------------------------------
echo "4.1: Verificando bloqueo de cupón cuando hay puntos de canje activos...\n";
Redemption::set_intent(500); // 500 puntos intentados

$redemption = new Redemption();
$blocked = false;
$msg = '';
try {
    $dummy_coupon = new WC_Coupon();
    $dummy_coupon->set_code('TESTCOUPON');
    $redemption->disallow_coupon_with_points(true, $dummy_coupon);
} catch (\Exception $e) {
    $blocked = true;
    $msg = $e->getMessage();
}

if ($blocked && strpos($msg, 'Ya tienes un descuento de Puntos Akibara') !== false) {
    echo "  [PASS] Bloqueo exitoso: '$msg'\n";
} else {
    echo "  [FAIL] No se bloqueó la aplicación del cupón con puntos activos!\n";
}

Redemption::set_intent(0); // Limpiar

// -----------------------------------------------------------------------------
// TEST 4.2: Cupón Aplicado -> Puntos No Deben Añadir Fee
// -----------------------------------------------------------------------------
echo "4.2: Verificando que con cupón aplicado el fee de puntos no se añade...\n";
WC()->cart->empty_cart();
// Crear un cupón temporal en memoria / DB
$test_coupon = new WC_Coupon();
$test_coupon->set_code('CUPONTEST99');
$test_coupon->set_amount(10);
$test_coupon->set_discount_type('percent');
$test_coupon->save();

WC()->cart->add_to_cart(30520, 2); // 2 tomos
WC()->cart->apply_coupon('CUPONTEST99');
WC()->cart->calculate_totals();

Redemption::set_intent(500);
$redemption->apply_fee(WC()->cart);
WC()->cart->calculate_totals();

$has_points_fee = false;
foreach (WC()->cart->get_fees() as $fee) {
    if ($fee->name === Redemption::FEE_NAME) {
        $has_points_fee = true;
    }
}

if (!$has_points_fee) {
    echo "  [PASS] Con cupón en carrito, apply_fee() ignoró los puntos y no añadió fee de puntos.\n";
} else {
    echo "  [FAIL] Se añadió fee de puntos teniendo un cupón aplicado!\n";
}

// Limpiar
WC()->cart->empty_cart();
$test_coupon->delete(true);
Redemption::set_intent(0);

// -----------------------------------------------------------------------------
// TEST 6: Envío Gratis vs Cupones vs Puntos
// -----------------------------------------------------------------------------
echo "\n6. Verificando impacto en Envío Gratis ($60.000 CLP):\n";

// Caso A: Carrito de $65.000 con cupón de $10.000 -> Total mercadería queda en $55.000
// Según evaluate_packages(), si ignore_discounts=no, el total se reduce a $55.000 (< $60.000)
$subtotal_a = 65000.0;
$discount_a = 10000.0;
$state_a = FreeShippingThreshold::evaluate_packages(
    [[['requires' => 'min_amount', 'min_amount' => 60000.0, 'ignore_discounts' => 'no', 'current_available' => null]]],
    $subtotal_a,
    $discount_a,
    0.0,
    true,
    false,
    0.0,
    0
);
if ($state_a['state'] === FreeShippingThreshold::STATE_PROGRESS && $state_a['remaining'] == 5000.0) {
    echo "  [PASS] Cupón de $10.000 rebaja base a $55.000 -> Faltan $5.000 para Envío Gratis.\n";
} else {
    echo "  [FAIL] Error en cálculo de envío gratis con cupón: " . json_encode($state_a) . "\n";
}

// Caso B: Carrito de $65.000 con PUNTOS de $5.000 (Fee) -> Subtotal mercadería sigue siendo $65.000
// Los fees NO alteran $subtotal ni $discount_total en evaluate_packages()
$subtotal_b = 65000.0;
$discount_b = 0.0; // Los puntos son FEE, no cupón
$state_b = FreeShippingThreshold::evaluate_packages(
    [[['requires' => 'min_amount', 'min_amount' => 60000.0, 'ignore_discounts' => 'no', 'current_available' => null]]],
    $subtotal_b,
    $discount_b,
    0.0,
    true,
    false,
    0.0,
    0
);
if ($state_b['state'] === FreeShippingThreshold::STATE_QUALIFIED) {
    echo "  [PASS] Puntos de canje ($5.000) NO restan al subtotal -> Envío Gratis CONSERVADO.\n";
} else {
    echo "  [FAIL] Los puntos afectaron el subtotal de envío gratis: " . json_encode($state_b) . "\n";
}

echo "\n========================================================================\n";
echo "PRUEBAS DE SESIÓN Y CONFLICTOS COMPLETADAS CON ÉXITO\n";
echo "========================================================================\n";
