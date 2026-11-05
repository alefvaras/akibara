---
trigger: always_on
description: Contratos de arquitectura mantenible, separación de código y datos, pruebas y promoción de Akibara.
---

# Arquitectura, calidad y entrega

Estas reglas son obligatorias para todos los modelos de Antigravity y los demás agentes del proyecto.

- Investigar la causa y los contratos antes de implementar. Mantener el monolito modular y usar las APIs de WordPress/WooCommerce. Evitar callbacks correctivos, duplicación de lógica, excepciones permanentes, código desactivado o adaptadores propios cuando la plataforma resuelve el contrato. Un cambio arquitectónico debe justificar su coste y mantenimiento.
- Conservar el comportamiento productivo validado. Una diferencia del espejo no autoriza inventar otra UX ni modificar cantidades físicas, precios, fechas prometidas o pedidos. El catálogo y el inventario productivo no se reconstruyen desde datos locales.
- Versionar fuentes revisadas, fixtures sintéticas y locks de herramientas/proveedores. Nunca publicar el historial local con datos privados, una base de datos, uploads, reseñas, clientes, pedidos, puntos, sesiones, credenciales o respaldos. La selección de fuentes y el guard pre-push se revisan antes de publicar.
- Instalar herramientas desde sus locks y ejecutar `npm run build` y `npm test`. El build genera tema y Search con el mismo esbuild fijado. CI instala los proveedores exactos desde `tools/ci-dependencies/dependencies.lock.json`; no actualiza pins ni acepta hashes nuevos automáticamente.
- Los paquetes admiten sólo runtime. El verificador común rechaza datos privados incluso cuando el ZIP y su inventario estén correctamente sellados. No desplegar un árbol completo del espejo ni hacer `git pull` dentro de la raíz pública.
- Medir los instrumentos con controles negativos y probar desde fuentes limpias. Una prueba que depende de mtimes distintos, archivos no declarados o configuraciones de otra máquina debe corregir su medición, sin ocultar una falla real ni saltarse la cobertura.
- Pruebas frontend con DOM Test Harness: Para verificar interactividad, despacho de eventos, accesibilidad ARIA o sincronización de UI en scripts de cliente (tema, bloques o plugins), es OBLIGATORIO usar el arnés centralizado (`tools/testing/dom-harness.js`) con `happy-dom` y `node:test`. Queda prohibido afirmar sobre el comportamiento de la interfaz mediante regexes estáticas sobre cadenas de código (`assert.match`) o levantar mocks manuales ad-hoc de `window`/`document` en pruebas aisladas.
- Ensayar localmente el candidato exacto. Conservar la recuperación únicamente en local, promover el artefacto revisado, verificar hashes, salud, cachés y superficie pública en producción. Retirar y verificar los temporales remotos y el mantenimiento propio.
- Un autor no cierra su arreglo: QA independiente vuelve a medir. Hasta entonces se registra pendiente. Actualizar el grafo sólo después de aprobar el estado productivo final.
- Las migraciones de datos tienen un plan propio derivado de producción, protección de compromisos activos, relecturas, control de concurrencia y ensayo. Un rollback SQL no revierte correos ni efectos externos. No añadir un normalizador runtime para maquillar datos antiguos ni declarar verde un control que aún encuentra excepciones.

La elección de modelo no exime ningún control. Si falta una capacidad, un modelo o un permiso, se comunica la limitación y se avanza sólo con el trabajo independiente ya autorizado. No se certifica ausencia universal de deuda con un conteo de tests.

Procedimiento y comandos: [entrega de runtime](../../docs/entregas/runtime-quality.md).
