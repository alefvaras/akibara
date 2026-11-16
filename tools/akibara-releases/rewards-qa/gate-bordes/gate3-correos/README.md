# Sondas del gate de CORREOS (2026-09-01)

Todo es re-ejecutable contra el espejo `http://localhost:8080` + Mailpit
`http://localhost:8025`. **En serie**: un solo proceso contra el espejo.

## 1. Sembrar (los `.php` necesitan estar DENTRO de `public_html/`)

`/private/tmp` no llega a Docker. Copiar y borrar al terminar:

```sh
cp gate-bordes/gate3-correos/*.php public_html/akb-gate3/
docker compose run --rm -T cli wp eval-file /var/www/html/akb-gate3/tool.php users --path=/var/www/html
docker compose run --rm -T cli wp option update akb_sf_enabled yes --path=/var/www/html   # el carril viene APAGADO por defecto
docker compose run --rm -T cli wp eval-file /var/www/html/akb-gate3/tool.php encolar <uid> <term>:<product>:<tomo> --path=/var/www/html
docker compose run --rm -T cli wp eval-file /var/www/html/akb-gate3/tool.php digest --path=/var/www/html
```

`pedidos.php` cubre pedidos bacs, preventa T-7/ready, BIS, cumpleaños,
vencimiento, reseña con foto e invitación. `cuentas.php` cubre registro y
reset de contraseña. **La siembra NO crea, edita ni borra términos**: encola
sobre productos y series que ya existen.

## 2. Medir

```sh
GATE3_OUT=<dir> GATE3_INV=inventario-ejemplo.json node gate3-correos.mjs
GATE3_INV=inventario-ejemplo.json node gate3-paleta.mjs
GATE3_OUT=<dir> GATE3_URL_SERIE=<url> GATE3_URL_MALA=<url> node gate3-pantallas-baja.mjs
GATE3_OUT=<dir> GATE3_USER=qa_gate3_a node gate3-toggle2.mjs
GATE3_OUT=<dir> GATE3_PASO=<asunto-serie|monto-cumple|apagar-cumple|encender-cumple|estado-invitacion|ventana-despacho|aviso-santiago> node gate3-config.mjs
```

`inventario-ejemplo.json` lleva los IDs de Mailpit de la corrida del
2026-09-01; hay que regenerarlos (los IDs son por mensaje).

**Control negativo del medidor de desborde** (un check que no puede fallar no
es un check): inyectar `<table style="width:1200px">` tras `<body…>` debe
llevar `scrollWidth` de 375 a 1200 y `nDesbordes` de 0 a 4.

## 3. Restaurar

```sh
docker compose run --rm -T cli wp eval-file /var/www/html/akb-gate3/limpiar.php --path=/var/www/html
rm -rf public_html/akb-gate3
```

Y a mano: borrar de Mailpit por ID los mensajes nacidos en la corrida, borrar
las options que ANTES no existían (guardar cualquier tab del panel materializa
decenas de defaults) y devolver `akb_rewards_birthday_points` a 1000.
