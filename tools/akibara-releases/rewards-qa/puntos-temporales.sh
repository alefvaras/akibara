#!/bin/bash
# QA · acredita o descuenta puntos por la API del Ledger (no por SQL: el saldo
# vive en usermeta y el ledger es el diario; tocarlos por separado los desincroniza).
# Uso: bash puntos-temporales.sh <mas|menos> <puntos>
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-pts.php <<'PHPEOF'
<?php
$signo  = getenv( 'AKB_SIGNO' );
$puntos = (int) getenv( 'AKB_PUNTOS' );
$uid    = 1;
if ( $puntos <= 0 ) { echo "falta AKB_PUNTOS\n"; return; }
$nota = 'QA cowork-banner: comprobación del canje en el checkout (se revierte)';
if ( 'mas' === $signo ) {
	$r = \Akibara\Rewards\Points\Ledger::credit( $uid, $puntos, 'admin_adjust', 'user', $uid, $nota, false );
} else {
	$r = \Akibara\Rewards\Points\Ledger::debit( $uid, $puntos, 'admin_adjust', 'user', $uid, $nota, false );
}
echo 'resultado=' . var_export( $r, true ) . "\n";
echo 'saldo=' . \Akibara\Rewards\Points\Ledger::balance( $uid ) . "\n";
echo 'historico=' . \Akibara\Rewards\Points\Ledger::total_earned( $uid ) . "\n";
PHPEOF
AKB_SIGNO="${1:-mas}" AKB_PUNTOS="${2:-900}" /usr/local/bin/wp eval-file /tmp/qa-pts.php 2>&1 | tail -4
rm -f /tmp/qa-pts.php
