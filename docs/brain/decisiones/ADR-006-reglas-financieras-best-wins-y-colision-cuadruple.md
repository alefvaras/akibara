# ADR-006: Reglas Financieras de Anti-Apilamiento (Best-Wins), Puntos Akibara y Pack

- **Estado:** Aceptado (Evolucionado en ADR-010 y ADR-012)
- **Fecha:** 2026-11-04 (Actualizado: 2026-11-06)

> [!NOTE] Nota de Vigencia y Evolución (ADR-010 & ADR-012)
> 1. La promoción «Arma tu Pack» (`BundleCouponEngine` / `SeriesBundles` / BYP) fue completamente **erradicada** en [ADR-010](ADR-010-erradicacion-preventa-a-pedido-packs-y-ediciones-pais.md) para simplificar el modelo comercial bajo inventario 100% físico.
> 2. El umbral de Envío Gratuito fue actualizado de \$40.000 CLP a **\$60.000 CLP** en [ADR-012](ADR-012-optimizaciones-financieras-carencia-rewards-y-batch-atc.md).
> 3. Los principios financieros de incompatibilidad entre Cupones vs Puntos (`Redemption`) y la acumulación de puntos exclusivamente sobre dinero neto pagado se mantienen plenamente vigentes e inmutables.
- **Contexto:**
  Akibara combina múltiples incentivos comerciales:
  1. Descuento automático por volumen "Arma tu Pack" (10% en 5 tomos de la misma serie).
  2. Cupón de bienvenida (10% OFF para nuevos suscriptores).
  3. Programa de fidelización Akibara Puntos (1 punto = $1 CLP).
  4. Umbral de Envío Gratis ($40.000 CLP).
  Existe riesgo de doble descuento o duplicación si estas promociones se acumulan indebidamente.

- **Decisión:**
  Se aplican tres principios financieros estrictos:
  1. **Anti-Apilamiento (Best-Wins):** Un cupón de descuento porcentual y el descuento de "Arma tu Pack" son mutuamente excluyentes. El sistema compara el beneficio total y aplica automáticamente el descuento más conveniente para el cliente (`BundleCouponEngine::manage_cart_coupons`).
  2. **Incompatibilidad Cupones vs Puntos:** No se permite aplicar el fee de Puntos Akibara cuando existe un cupón activo en el carrito (`Redemption::apply_fee` aborta con excepción oficial). Si el cliente desea usar el cupón, debe remover los puntos previamente.
  3. **Switch de Preferencia (Pack vs Puntos):** Si un cliente tiene tomos que califican para el Pack y además saldo de Puntos, rige por defecto el Pack. El carrito provee los controles `?akb_prefer_points_switch=1` y `?akb_prefer_bundle_switch=1` para alternar la preferencia de forma transparente.
  4. **Acumulación (Earn) sobre Dinero Real:** Los puntos generados por una compra (2%) se calculan de forma estricta sobre el importe neto pagado en dinero, descontando cupones, promociones y puntos canjeados (cero duplicidad).

- **Consecuencias:**
  - Cero fugas de margen por colisión promocional.
  - Comprobado mediante auditoría técnica automatizada (`tools/test-quadruple-collision.php`) con paridad 100% entre local y producción.
