# Calidad y empaquetado de Akibara Business

Este tooling vive fuera del plugin y es exclusivamente local. Ninguna
dependencia de Composer o Node se incluye en QA o producción.

## Preparación

Con Docker disponible:

```bash
./tools/akibara-business/install.sh
```

Composer queda fijado por `composer.lock` dentro de este directorio y descarga
las dependencias a `tools/akibara-business/vendor/`.

## Gate local

Con el WordPress Docker local activo:

```bash
docker compose up -d
./tools/akibara-business/qa.sh
```

El gate ejecuta, en orden:

1. `php -l` sobre todo el PHP del plugin usando el contenedor WordPress;
2. `node --check` sobre todos los assets JavaScript, sin instalar paquetes npm;
3. detección de respaldos y artefactos temporales;
4. PHPUnit en la misma imagen PHP 8.4 que usa WordPress local;
5. construcción temporal y verificación del paquete distribuible.

El `local-gate` de promoción ejecuta además
`tools/akibara-releases/qa-checkout.sh`: el adaptador separado de Cart &
Checkout Blocks debe pasar su frontera arquitectónica, PHP lint, sintaxis
JavaScript, pruebas Node y contrato de paquete antes de emitir el recibo Docker.

## Paquete distribuible

```bash
./tools/akibara-business/package.sh
```

El wrapper delega al contrato reproducible único de
`tools/akibara-releases/package-runtime.sh`. En `dist/` se crean el ZIP, el
inventario SHA-256 por archivo y el sidecar SHA-256 del ZIP. La lista explícita
de rutas runtime y una segunda inspección del ZIP excluyen y bloquean:

- `vendor/`, `node_modules/` y archivos de gestores de paquetes;
- `tests/`, configuración/caché de PHPUnit y archivos de Graphify;
- `.bak`, `.orig`, `.rej`, `.tmp`, `*~` y `.DS_Store`.

QA y producción deben recibir únicamente el contenido de este paquete, nunca
una copia directa del directorio de desarrollo del plugin.
