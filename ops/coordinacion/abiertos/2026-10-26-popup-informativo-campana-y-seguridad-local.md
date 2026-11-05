de: promotion_popup_qa (desarrollo local)
para: root (QA independiente y coordinación con Gemini)
estado: esperando-respuesta — DESPLEGADO en producción; ambos switches verificados OFF en producción y secretos aislados en configuración privada
fecha: 2026-10-26
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/WelcomeCoupon/Settings.php; public_html/wp-content/plugins/akibara-business/src/Modules/WelcomeCoupon/PopupRenderer.php; public_html/wp-content/plugins/akibara-business/src/Modules/WelcomeCoupon/RestController.php; public_html/wp-content/plugins/akibara-business/assets/js/welcome-popup.js; public_html/wp-content/plugins/akibara-business/tests/Modules/WelcomeCoupon/WelcomeCouponTest.php; public_html/wp-content/plugins/akibara-business/tests/Modules/WelcomeCoupon/InformativeCampaignTest.php; public_html/wp-content/plugins/akibara-business/tests/Modules/WelcomeCoupon/welcome-popup.test.mjs; public_html/wp-content/mu-plugins/akibara-provider-access.php; public_html/wp-content/plugins/akibara-business/tests/MuPlugins/ProviderAccessTest.php

# Popups: separar captación de bienvenida de información de campañas automáticas

Orden del dueño: bienvenida capta correo; promociones informan los descuentos ya aplicados, sin captación y con la misma vigencia que la campaña y la franja. Pruebas locales; mantener ambos dormidos. Root confirma qué se hará después de QA. No se modifica cálculo de precios, stock ni descuentos.

## Baseline verificado

- Ambos switches están OFF en local y producción. Snapshot sin secretos en `output/seo-growth-20261026/qa-integral/popup-*-before.json`.
- El modo informativo ya existe si `promo_code` está vacío, pero las copias guardadas productivas todavía hablan de aplicar un código vacío.
- La barra YA sigue descuentos a través de `DiscountCopy::campaign_active()` cuando su opción de seguimiento está encendida. El popup aún no usa ese contrato.
- La cookie de bienvenida completada corta también el popup promocional, incluso si su audiencia es todo visitante.
- La vista previa descrita como privada no comprueba capacidad; no debe abrir emisión de cupones estando OFF.
- Se detectó un fallback de secreto embebido en fuente y dos caminos fail-open en verificación. Ningún valor secreto se incluye en esta coordinación, snapshots o informes. La migración a configuración privada queda coordinada con root antes de cualquier promoción.

## Candidato

Completar vínculo a campaña vigente para información, defaults/copies heredadas seguros, placeholders compartidos de descuento; limitar cookies completadas a bienvenida. Retirar secreto de fuente y usar catálogo privado; fallar cerrado ante falta de configuración o fallos de verificación. Pruebas de render, OFF y negativos; restauración local y ausencia de correos reales.

No cerrar este ticket por el autor. Pendientes QA independiente y cualquier decisión de despliegue.
