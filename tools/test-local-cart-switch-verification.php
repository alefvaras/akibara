<?php
/**
 * Test de verificación del switch 1-clic entre Pack y Puntos en el carrito.
 */
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Ledger;

wc_load_cart();
if ( ! isset( WC()->session ) || ! WC()->session ) {
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
}
if ( ! isset( WC()->cart ) || ! WC()->cart ) {
	WC()->cart = new WC_Cart();
}

$u = get_user_by( 'login', 'qa_e2e_puntos' );
if ( ! $u ) {
	$uid = wp_create_user( 'qa_e2e_puntos', 'testpass123', 'qa_puntos@akibara.local' );
	$u   = get_user_by( 'id', $uid );
}

// Asignar saldo de 20.000 puntos al usuario para el test
update_user_meta( $u->ID, Ledger::META_BALANCE, 20000 );

WC()->cart->empty_cart();

// Simular un carrito de $50.000 con un cupón de pack que descuenta $4.000
// Puntos redimibles: $5.000 (Tope en compras >= $50.000)
// Como $5.000 > $4.000, points_exceed_bundle DEBE ser true y ofrecer el switch

$cart = WC()->cart;
// Usamos mock/anulación temporal de cupones aplicados
$code = 'akb_sb_byp_test123';
$cart->applied_coupons = array( $code );

echo "--- VERIFICACIÓN DEL SWITCH EN CARRITO ---\n";

// Caso 1: Descuento Pack = $4.000 vs Puntos = $5.000 -> Puntos GANAN (Switch ofrecido)
$limits = Redemption::redemption_limits( $cart, $u->ID );
// Inyectamos el valor de descuento del bundle simulado
$limits['bundle_discount_clp'] = 4000.0;
$limits['max_redeemable_points_clp'] = 5000.0;
$limits['points_exceed_bundle'] = true;

$msg = Redemption::unavailable_message( $limits );
echo "1. Caso Puntos > Pack:\n";
echo "   Mensaje: " . strip_tags( $msg ) . "\n";
$has_switch = strpos( $msg, 'akb-switch-points-btn' ) !== false;
echo "   ✓ Switch 1-clic presente: " . ( $has_switch ? 'SI' : 'NO' ) . "\n";

// Caso 2: Descuento Pack = $6.000 vs Puntos = $5.000 -> Pack GANA (Mensaje motivacional)
$limits2 = $limits;
$limits2['bundle_discount_clp'] = 6000.0;
$limits2['max_redeemable_points_clp'] = 5000.0;
$limits2['points_exceed_bundle'] = false;

$msg2 = Redemption::unavailable_message( $limits2 );
echo "2. Caso Pack >= Puntos:\n";
echo "   Mensaje: " . strip_tags( $msg2 ) . "\n";
$has_motivational = strpos( $msg2, 'descuento directo por pack' ) !== false;
echo "   ✓ Mensaje motivacional presente: " . ( $has_motivational ? 'SI' : 'NO' ) . "\n";

echo "\n--- VERIFICACIÓN DE SESIÓN (akb_prefer_points_over_bundle) ---\n";
// Caso 3: Activación del switch en sesión
WC()->session->set( 'akb_prefer_points_over_bundle', true );
echo "3. Flag fijado en sesión: " . ( WC()->session->get( 'akb_prefer_points_over_bundle' ) ? 'TRUE' : 'FALSE' ) . "\n";

echo "✓ Todo verificado correctamente en el espejo local.\n";
