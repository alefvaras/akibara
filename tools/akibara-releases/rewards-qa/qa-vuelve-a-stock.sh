#!/bin/bash
# QA · flujo «avísame cuando vuelva»: pone stock a un agotado, mira si se encola
# el aviso, y restaura. Uso: bash qa-vuelve-a-stock.sh <id> <paso>
#   paso = foto | encender | mirar | restaurar
cd ~/domains/akibara.cl/public_html || exit 1
ID="${1:-13384}"
PASO="${2:-foto}"
WP=/usr/local/bin/wp

foto() {
  echo "producto $ID"
  echo -n "  _stock_status = "; $WP post meta get "$ID" _stock_status 2>/dev/null || echo '(sin)'
  echo -n "  _stock        = "; $WP post meta get "$ID" _stock 2>/dev/null || echo '(sin)'
  echo -n "  _manage_stock = "; $WP post meta get "$ID" _manage_stock 2>/dev/null || echo '(sin)'
  echo -n "  _backorders   = "; $WP post meta get "$ID" _backorders 2>/dev/null || echo '(sin)'
  echo "  suscriptores del producto:"
  $WP db query "SELECT id, email, status, notified_at FROM wp_akb_bis_subscribers WHERE product_id=$ID"
  echo "  acciones bis en cola:"
  $WP db query "SELECT action_id, status, scheduled_date_gmt, LEFT(args,40) args FROM wp_actionscheduler_actions WHERE hook='akb_bis_notify_product' ORDER BY action_id DESC LIMIT 4"
}

case "$PASO" in
  foto) foto ;;
  encender)
    $WP post meta update "$ID" _manage_stock yes >/dev/null
    $WP post meta update "$ID" _stock 2 >/dev/null
    $WP post meta update "$ID" _stock_status instock >/dev/null
    $WP wc product update "$ID" --stock_quantity=2 --stock_status=instock --user=1 >/dev/null 2>&1
    echo "puesto en stock"; foto ;;
  mirar) foto ;;
  restaurar)
    $WP post meta update "$ID" _stock 0 >/dev/null
    $WP post meta update "$ID" _stock_status outofstock >/dev/null
    $WP wc product update "$ID" --stock_quantity=0 --stock_status=outofstock --user=1 >/dev/null 2>&1
    echo "restaurado a agotado"; foto ;;
esac
