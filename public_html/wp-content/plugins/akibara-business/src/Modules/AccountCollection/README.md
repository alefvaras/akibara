# Colección del cliente (Compras verificadas)

Autoridad de compras verificadas del cliente para `SeriesFollow`, `BackInStock`, `SmartCartRecommender`, `AccountRewards` y la landing de serie (`SeriesBatchAtc`). La estantería manual `/mi-cuenta/coleccion/` fue retirada: no existen marcas manuales ni caché estática de catálogo.

## Responsabilidades

- `CustomerCollection`: compras netas verificadas de WooCommerce (consultas HPOS/CPT descontando reembolsos). Única autoridad de propiedad.
- `CollectionOwnership`: propiedad compartida con avisos BIS, incluida equivalencia inequívoca de variantes dentro de la misma edición `pa_serie`.

## Integración en landings de serie (`/serie/<slug>/`)

`SeriesBatchAtc` consulta directamente los productos publicados del término `pa_serie` actual y cruza las compras netas verificadas de `CustomerCollection` (incluyendo equivalencia de tomo/edición). Los tomos comprados se muestran con un badge `✓ Comprado` (`data-owned="1"`) y `series-multiselect.js` los excluye automáticamente de «Seleccionar disponibles» y «Seleccionar tramo», manteniendo la posibilidad de marcarlos manualmente para comprar una copia extra.
