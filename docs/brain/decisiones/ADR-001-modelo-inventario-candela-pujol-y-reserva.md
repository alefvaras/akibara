# ADR-001: Modelo Inviolable de Inventario, Proveedores y Descuento de Reserva (Regla 18)

- **Estado:** Aceptado e Implementado
- **Fecha:** 2026-11-02 (Aprobado por el dueño)
- **Contexto:**
  Akibara comercializa productos con unidades físicas presentes en la bodega de Santiago y productos bajo modalidad "A pedido / Reserva" respaldados por acuerdos con distribuidoras oficiales (Candela para Ivrea Argentina / Ovni Press; Pujol & Amadó para Panini y Planeta Cómic; Milky Way Ediciones directa).
  Históricamente existió riesgo de confusión entre stock disponible físico e inventario virtual de catálogo.

- **Decisión:**
  Se establece el contrato nativo estricto de WooCommerce para los tres estados comerciales:
  1. `instock`: Exclusivo para bodega física. Requiere `_manage_stock = 'yes'`, `_stock > 0`, `_backorders = 'no'`.
  2. `onbackorder` (A pedido): `_manage_stock = 'no'`, `_stock = null`, `_backorders = 'no'` (el producto se mantiene comprable nativamente).
     - 5% de descuento obligatorio en `_sale_price` con redondeo a la centena `round_100`:
       $$\text{precio\_oferta} = \operatorname{round}\left(\frac{\text{regular} \times 0.95}{100}\right) \times 100$$
     - Término `outofstock` removido de `product_visibility`.
  3. `outofstock` (Agotado): Ni en bodega física ni en distribuidor.
     - `_manage_stock = 'no'`, `_stock = null`, `delete_post_meta('_sale_price')`.
     - Término `outofstock` asignado en `product_visibility`.

- **Consecuencias y Blindaje:**
  - Prohibición absoluta de volcado de stock desde Docker local hacia producción.
  - Los compromisos comerciales de clientes (pedidos existentes) son sagrados e inmutables.
  - Guardrail de verificación obligatoria pre-despliegue: `python3 ops/guardrail-inventario-akibara.py --target prod`.
