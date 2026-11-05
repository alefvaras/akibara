de: root, con reviews_carousel_dev, brand_p2_audit y roadmap_p2_p3
para: desarrollo y QA independiente del lote
estado: en-curso — revalidación de P2/P3 original y ampliación de reseñas/carrusel autorizada por el dueño
archivos: carriles delimitados en output/ux-p2-p3-20261015/pendientes-verificados.md; reseñas Carousel.php, Options.php, SettingsPage.php y ReviewForm.php de akibara-rewards; fuentes de tema/business pendientes de reclamo exacto de cada autor

# Alcance autorizado el 15 de octubre

El dueño pide completar las prioridades restantes, evaluar la gama de colores conservando la identidad, consultar en lectura la cuenta y pedidos de alejandro.fvaras@gmail.com y mejorar reseñas/puntos/carrusel desde su UI configurable existente. Autoriza crear reseñas de prueba en el espejo. No se publican reseñas ficticias en producción.

P1 C1–C6 permanece cerrado. La matriz original recuperada identifica V1–V4/A1–A2 en P2; V5/V6 y documentación en P3. C5 ya fue cerrado dentro de P1. Cada observación antigua se vuelve a medir antes de modificarla; las preferencias visuales se identifican como tales.

## Responsables

- reviews_carousel_dev: modos del carrusel y presentación con/sin foto; mensajes del formulario y UI existente. Baseline producción/local de ocho archivos exacto. No modifica montos, motor de puntos ni la cuenta consultada.
- brand_p2_audit: colores, contraste, legibilidad A1/A2 y consistencia V5, con archivos concretos antes de edición.
- roadmap_p2_p3: V1/V2/V3/V4/V6 y documentación de componentes; no modifica colores globales ni reseñas.
- root: coordinación, cuenta en lectura, UI oficial productiva cuando el candidato local esté aprobado, promoción acotada y revisión independiente de candidatos ajenos.

## Gates

Investigación y preflight → implementación local → pruebas visuales/funcionales y QA independiente → respaldo sólo local y promoción exacta → UI oficial para ajustes → QA productiva independiente → limpieza contada → Graphify final acotado. Desarrollo no cierra su propia corrección.

## Hechos iniciales verificados

- UI oficial productiva de Puntos y Reseñas: carrusel activo, mínimo ocho reseñas, dos elegibles; por eso todavía no aparece en portada. Hoy existe título editable, límite de tarjetas, mínimo de caracteres y exclusión de premiadas. No existe selector foto/sin foto/mixto.
- Motor y UI permiten opinión sin foto sin premio; premio requiere foto real y condiciones configuradas. La aprobación, estrellas y premios son conceptos distintos.
- Cuenta solicitada #192: saldo cero, ledger vacío, Woo no marca correo verificado; dos pedidos vinculados directamente, ambos cancelados. Coincidencias históricas por correo de facturación pertenecen a invitados u otra cuenta y se mantienen separadas.
- MCP Rank Math/WordPress devolvió Transport closed al descubrimiento; lectura puntual mediante WP-CLI oficial por SSH. Ningún archivo temporal remoto creado.

## Estado de cierre

Pendientes: candidatos, gates locales, despliegue, ajustes UI, QA productiva y Graphify. Este documento no declara completada la ampliación.
