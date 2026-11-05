de: sesión bis-avenida [d5a6502d] (desarrollo)
para: cualquiera — referencia, no reclama nada
estado: abierto
archivos: (ninguno — lectura de plugins de terceros, no se copió código)
prueba: los tres plugins quedaron en el scratchpad de la sesión; se descargaron de wordpress.org y sólo se LEYERON

# Cómo resuelven otros plugins lo que nos duele a nosotros

Encargo del dueño. Leídos **Discount Rules for WooCommerce 2.6.17** (Flycart),
**Advanced Dynamic Pricing** (AlgolPlus) y **ACO Dynamic Pricing**. Ninguno se
instaló ni se ejecutó, y no se copió código al repo.

## La pregunta que más importaba: ORDENAR POR PRECIO

Es un defecto vivo nuestro: el catálogo ordena por `wc_product_meta_lookup.min_price`,
que no incluye un descuento que se aplica al pintar.

**Dos de tres no lo resuelven ni lo intentan.** La prueba dura: Flycart tiene su
propia página de «Ofertas» —donde el 100% de los productos tiene descuento— y ahí
ordena por `_price` sin descuento (`ShortCodeManager.php:293-304`). Su orden por
defecto en esa página es alfabético: se dieron cuenta y se apartaron.

**ADP sí lo resuelve, materializando**: escribe el precio calculado en
`wc_product_meta_lookup.min_price` (`PersistentRuleRepository.php:512-538`) y
repone el valor cuando alguien regenera las tablas de búsqueda (`:544-560`), que
es la pieza que todos olvidan. Pero: está apagado por defecto, sólo cubre su
regla «Product only», **no escribe `max_price`**, fuerza `onsale=1` —lo que
enciende las colecciones «en oferta» del núcleo sin avisar— y no barre nada
cuando la campaña caduca.

**Conclusión: el defecto es del oficio, no nuestro. Y estamos más cerca que
ninguno de arreglarlo**, porque la pieza cara ya existe en casa:
`akb_search_docs.price_effective` (`akibara-search/src/Install.php:2093`), poblado
desde el proveedor estricto y vigilado contra deriva por `Reconciler.php:340`.
Falta el cable: cuando haya campaña y el cliente pida orden por precio, resolver
el orden desde ahí y entregarlo como `post__in`, igual que ya hacemos con la
relevancia en `FacetQueryIntegration.php:363-368`.

## Lo que merece adoptarse, por orden

1. **Cerrar el orden por precio con el índice que ya tenemos.** Coste medio.
2. **La campaña como línea propia en el carrito, el resumen y el correo.** Los
   TRES lo hacen, así que es el estándar del sector: un cupón que no existe en la
   base, materializado al vuelo por `woocommerce_get_shop_coupon_data`. El
   cliente vería «Campaña Ivrea −$2.400» en vez de un precio más bajo sin
   explicación. **Aviso**: chocaría con nuestra regla de que los cupones no se
   acumulan con el descuento por editorial; hay que sacarla del medio primero.
3. **Granularidad por superficie del tachado**: tres casillas para decidir en qué
   páginas se pinta el precio tachado. Coste bajo, encaja con «todo operable sin
   código».

## Lo que NO hay que copiar

- **La bomba nuclear**: dos de los tres traen una casilla que **desregistra los
  hooks de precio de todos los demás plugins**, recorriendo `$wp_filter` y
  borrando callbacks ajenos (Flycart `ManageDiscount.php:2774-2808`).
- **Escribir en la base al pintar una tarjeta de producto** (ACO, para alimentar
  feeds).
- **Vaciar la caché de objetos del sitio entero** en cada guardado de opciones
  (ADP, `CacheHelper::flush()` = `wp_cache_flush()`). Ya nos salió caro una vez
  borrar `notoptions`/`alloptions`.
- **Materializar a medias** (ver arriba: `min_price` sin `max_price`).
- **Guardas de recursión por presupuesto de iteraciones** en vez de candado.

## En qué estamos por delante, para no cambiar por cambiar

- **Nosotros cambiamos el precio de verdad; ellos lo pintan.** Filtramos
  `woocommerce_product_get_price` y compañía; ninguno de los tres lo hace para su
  propio motor. Consecuencia verificada: **nuestro precio de campaña ya sale en
  el feed de Google Shopping y en el schema de Rank Math** sin haber escrito nada
  para eso. Flycart necesita un parche por feed; ACO escribe en la base al pintar.
- **Elegibilidad por disponibilidad**: ninguno de los tres tiene filtro de stock.
  Nosotros sí, y además por estado comercial (disponible / a pedido / preventa).
- **El panel explica la decisión con un ejemplo numérico.** ADP tiene nuestras
  mismas cuatro políticas y las presenta como cuatro etiquetas secas.
- **Memoización que se usa**: la de Flycart se escribe y no se lee nunca — código
  muerto, cada pintada recorre el bucle entero de reglas.
- **El hash de precios de variaciones amasado con la firma de configuración**:
  ninguno de los tres lo toca.
- **Contexto `edit` en el resolver**, así que nuestros filtros no reentran. ADP
  intentó el camino contrario y dejó veinte líneas muertas con el comentario
  «Failed code, caused infinite loop».

## Dos correcciones a los propios lectores, que las verifiqué

1. **«Hay que copiar su JSON-LD porque nuestro precio no llega al schema»: FALSO.**
   Rank Math construye el schema desde `get_price()`, que nosotros filtramos. Ya
   llega. Copiarlo sería duplicar un mecanismo y arriesgar un doble descuento.
2. **«Hay que construir un índice de precio efectivo»: ya existe.** Lo que falta
   es la conexión final, no la obra.
