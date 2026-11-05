# business 1.116.11 — candados atómicos y portadas enteras

- **Abierto:** 2026-09-15 · **estado:** **desplegado en producción el 2026-09-15 ~01:55 CL**, borde purgado; dos vueltas de revisión independiente cerradas; verificado en la base real del espejo y de producción.
- **Reclama:** `public_html/wp-content/plugins/akibara-business/src/Utilities/OptionRow.php` (nuevo),
  `src/Modules/ImageQuality/Recompressor.php`, `src/Modules/SeoSocial/SocialImageDerivatives.php`,
  `src/Modules/LlmsManifest/LlmsManifest.php`, `src/Modules/Forms/ContactoLog.php`,
  `assets/css/{native-cart-v2,quick-view,account-collection,home-series-destacadas,brand-family}.css`,
  `tests/Support/OptionsRowWpdb.php` y `OptionsRowDouble.php` (nuevos), y sus pruebas.

## Candados: `add_option()` no es atómico

`add_option()` hace `INSERT … ON DUPLICATE KEY UPDATE`. Si la caché de objetos dice que la option no existe
—y en producción hay caché persistente de LiteSpeed—, **pisa la fila viva de otro proceso y devuelve
verdadero**. **Probado en la MariaDB real del espejo el 2026-09-15:** con `notoptions` desfasado,
`add_option()` reemplazó el valor `vivo` por `pisado`.

`OptionRow` usa las tres formas atómicas contra el índice único de `option_name` —`INSERT IGNORE`, `UPDATE …
WHERE option_value = <lo leído>` y `DELETE … WHERE option_value = <el propio>`— y lee con `SELECT` directo.
Dónde se usaba `add_option()` como coordinación:

| Módulo | Qué podía pasar | Ahora |
| --- | --- | --- |
| `SocialImageDerivatives` (JPG para correos y WhatsApp) | dos corridas a la vez; y el `delete_option()` final borraba el candado aunque ya fuera de otra | candado con token; suelta sólo el propio |
| `LlmsManifest` (`llms.txt`) | la vía rápida del lease pisaba un lease vivo | inserción atómica inyectable; su CAS ya era correcto |
| `ContactoLog` (bitácora del formulario) | un mensaje podía pisar otro con el mismo id | `INSERT IGNORE` |
| `Recompressor` | ya tenía SQL propio desde 1.116.10 | delega en `OptionRow` (misma SQL) |

`CustomerCollection` también usa `add_option()`, pero con un nombre que lleva `md5(uuid4)`: no puede
colisionar, se deja.

**Verificado contra MariaDB real** (`wp eval-file`, sonda fuera del árbol y retirada): inserción que no pisa,
`autoload = off`, CAS que falla con valor viejo y con el mismo valor (0 filas), borrado sólo del propio, el
generador social que no entra ni borra con un candado vivo ajeno, y la bitácora de contacto creando y
leyendo su fila (fila de prueba borrada).

## Portadas enteras en el plugin

Mismo criterio que el tema 2.1.128 (decisión del dueño 2026-09-15): `object-fit: contain` en el cajón del
carrito, la vista rápida, «Mi colección», la vitrina de series y las tarjetas de edición. Medido en el espejo
con WebKit: las cajas quedan 2:3 y la portada 0,70 del carrito se ve entera.

## Pruebas

Doble compartido `OptionsRowWpdb` con la semántica de MariaDB (una sola copia: `INSERT IGNORE` no pisa, un
`UPDATE` sin cambio afecta 0 filas); `RecompressorTest` lo extiende y `ContactoLogTest` también. Nuevas
pruebas de la utilidad, candados de fuente en los tres módulos (ninguno vuelve a `add_option()` para
coordinar) y la corrida social que no borra un candado ajeno. Suite 3.300 en verde con tres semillas
aleatorias; PHPCS en cero.

## Revisión independiente (dos vueltas) y lo que cambió por ella

- **Media, regresión que yo introducía:** `OptionRow::insert_if_absent()` no sacaba la clave de `notoptions`.
  `LlmsManifest` lee su lease con `get_option()`: tras desactivar y reactivar el plugin (que borra la option
  y la marca como inexistente), el lease quedaba sin poder soltarse ni tomarse y `llms.txt` se congelaba.
  En 1.116.10 no pasaba porque `add_option()` sí la quita. Arreglo: `unmark_missing()` tras insertar, como
  core. **Probado en MariaDB real:** option borrada y marcada en `notoptions`, reinsertada por SQL →
  `get_option()` la ve.
- **Bajas:** CAS y borrado con `BINARY option_value` (la intercalación ignoraba mayúsculas y espacios
  finales; probado en MariaDB real); el `UPDATE` ya no toca `autoload`; pruebas del candado social que
  ahora sí distinguen el código viejo (verificado por mutación: con el `delete_option()` incondicional la
  prueba falla); excepción del marcador cuadrado de WooCommerce en carrito y vista rápida; recomendaciones
  del carrito también en `contain`; stubs de caché y serialización centralizados en el instalador del doble
  (la prueba del botón del panel era dependiente del orden y aceptaba cualquiera de los tres resultados:
  ahora exige `encolada`).
- **La segunda vuelta levantó que el ZIP de `dist/` era anterior a las correcciones:** ya se había
  reempaquetado (01:49, SHA `2486f2ed…`) y se comprobó dentro del ZIP que trae `unmark_missing`, `BINARY` y
  las reglas del marcador antes de desplegar.

## En producción

- `wp_using_ext_object_cache()` = **true**: la caché persistente con la que `add_option()` pisa filas existe allí.
- Sonda con limpieza (fila temporal creada y borrada): inserción que no pisa, CAS, CAS binario y borrado propio, todo correcto.
- La pasada de recompresión, que corría con 1.116.10 al desplegar, siguió avanzando con 1.116.11 (176 → 178, 0 errores).
- Tienda en 200 (`/`, `/tienda/`, `/serie/one-piece/`, `/mi-cuenta/`).
