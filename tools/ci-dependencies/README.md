# Dependencias de contratos de CI

Este directorio fija las fuentes de terceros que leen las pruebas de Akibara.
No es una instalación de WordPress ni una herramienta para actualizar la tienda.
No carga WordPress, no accede a bases de datos y rechaza un árbol con
`public_html/wp-config.php`.

```sh
python3 tools/ci-dependencies/test_materialize.py
python3 tools/ci-dependencies/materialize.py
python3 tools/ci-dependencies/materialize.py --verify-only
```

Ejecutar en un checkout de fuentes limpias antes de PHPUnit. Para otro checkout,
usar `--root /ruta/al/checkout`. No ejecutarlo sobre el espejo configurado.
`--cache-dir` permite conservar los ZIP por SHA-256 fuera del historial;
`--offline` exige que cada archivo fijado ya esté presente. Tanto descargas como
caché se verifican por tamaño y SHA-256. Una descarga que cambie falla; nunca se
selecciona automáticamente `latest` ni otra versión.

El lock registra origen oficial, versión, SHA-256 del ZIP, tamaño y SHA-256 de
cada archivo materializado. Las fuentes oficiales se descargaron y verificaron
el 2026-11-03; sus archivos coinciden byte por byte con las dependencias usadas
en el espejo alineado:

| Dependencia | Versión | Alcance de las pruebas |
| --- | --- | --- |
| WordPress | 7.1.4 | `wp-includes/`, incluido el procesador HTML real |
| WordPress, administración | 7.1.4 | `wp-admin/`, incluidos los estilos reales contra los que se comprueban los overrides |
| WooCommerce | 11.2.1 | Fuentes completas del plugin |
| Mercado Pago | 8.9.3 | Fuentes completas del plugin y contratos de handles |
| BlueX | 3.3.2 | Instantánea contractual de sus 131 archivos oficiales |

Los archivos de WordPress son **entradas de pruebas**, no un sitio parcial
desplegable. Los paquetes de runtime propios no incluyen estas dependencias.
Las rutas de destino se conservan porque las pruebas actuales leen allí el
código del proveedor. Si falta un input, hay diferencias o sobran archivos,
la verificación falla; no se modifica un árbol existente.

BlueX requiere una excepción explícita: su
[API oficial de plugins](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=bluex-for-woocommerce&request%5Bfields%5D%5Bversions%5D=1)
no enumera versiones, y la descarga versionada 3.3.2 devolvió 404. El ZIP actual
[publicado por WordPress.org](https://downloads.wordpress.org/plugin/bluex-for-woocommerce.zip)
contenía la versión 3.3.2. Se conserva su código GPL, sin modificaciones, en
`vendor-contracts/bluex-for-woocommerce/`; su SHA-256 de origen y todos los hashes
de archivo están fijados. Materializar esta excepción no consulta la URL mutable.
La instantánea coincide con los 131 archivos comparados independientemente entre
producción y espejo durante esta entrega.

Para preparar una actualización reproducible y revisable:

```sh
python3 tools/ci-dependencies/prepare-lock.py \
  --wordpress 7.1.4 --woocommerce 11.2.1 --mercadopago 8.9.3 --bluex 3.3.2 \
  --output /ruta/nueva/candidato-dependencias
```

El preparador descubre los enlaces en los metadatos oficiales, exige versiones
exactas, verifica los headers y genera otro directorio; nunca cambia el lock
activo. Antes de adoptar un candidato, comparar contratos con la versión
productiva propuesta, revisar el diff y ejecutar todas las pruebas desde un
checkout nuevo. Un cambio de distribución exige una nueva revisión aunque
conserve el número de versión. La materialización de CI nunca actualiza pins.

Las descargas de WordPress proceden del
[archivo oficial](https://wordpress.org/download/releases/); WooCommerce y Mercado
Pago usan los enlaces versionados informados por la API de WordPress.org. La
validación de archivos ZIP rechaza traversal, duplicados, enlaces simbólicos y
entradas especiales, y acota el tamaño descomprimido. Primero valida todos los
inputs; después publica los directorios verificados mediante rename.
