# ADR-003: Nivelación de Precios por Serie y Autorización Obligatoria de Rescate

- **Estado:** Aceptado e Implementado (`akibara-business 1.120.11`)
- **Fecha:** 2026-11-04
- **Contexto:**
  Al actualizar precios de tomos importados por fluctuaciones cambiarias o incrementos editoriales, existían casos donde tomos de una misma serie y formato quedaban desalineados (ej. Attack on Titan Deluxe tomo 01 a $25.500 vs tomos 02 al 06 a $29.500; o Dorohedoro a $33.000).
  Adicionalmente, reactivar productos agotados (`outofstock` $\rightarrow$ `onbackorder`) sin validación manual violaba la Regla 15 y 18 si la distribuidora no confirmaba la reposición.

- **Decisión:**
  1. **Nivelador de Series:** Permite homologar los precios regulares y de oferta de todos los tomos de una misma serie y sello editorial al precio estándar definido por el catálogo vigente, respetando siempre el lock de pedidos de `OrderLockGuard`.
  2. **Rescue Authorization Gate:** Cualquier intento de rescatar productos agotados a "A pedido" exige de forma obligatoria los metadatos de autorización del distribuidor:
     - `supplier`: Candela, Pujol & Amadó, Milky Way o Panini.
     - `reference`: Número de factura, orden de compra o lista de disponibilidad oficial.
     - `confirmed`: Declaración afirmativa del operador.
  3. **Trazabilidad:** Se registra `_akb_rescue_supplier`, `_akb_rescue_ref`, `_akb_rescue_at` y `_akb_rescue_by` en el postmeta del producto.

- **Consecuencias:**
  - Se eliminan discrepancias visuales de precios entre tomos de la misma serie.
  - Se evita ofrecer en la tienda títulos descatalogados o sin tiraje disponible en editorial.
