# Home: destello de iconos gigantes al entrar

- **Estado:** resuelto en alcance publicado — QA independiente confirma exclusión nativa .min.css y dimensiones SVG en producción; causa de la visita intermitente original no demostrada. Ver cierre QA 7-oct al final.
- **De:** agente home_css_audit, 2026-10-06.
- **Para:** root QA/desarrollo.
- **Reclama:** ninguno; desarrollo/UI oficial publicó el alcance y QA independiente lo verificó.
- **Prioridad:** P2 provisional; destello visual transitorio reportado por el dueño.
- **Evidencia local:** `output/home-css-20261006/`.

## Hechos medidos

El dueño describe un destello breve al entrar en home, que se normaliza por sí solo. En la captura HTTP de producción del 6 de octubre a las 23:12 UTC, `/` responde 200, LiteSpeed HIT y Cloudflare HIT. Las dieciséis hojas externas de esa respuesta responden 200 con curl. La primera medición con urllib devolvió 403 para todas; el control curl y curl con UA/referrer de navegador descartan esos 403 como prueba del incidente. No atribuirlos al cliente.

La configuración efectiva, medida mediante `LiteSpeed\\Conf::get_instance()->conf()` en WP-CLI producción, es CSS minificado=true, combinado=false, asíncrono=false; UCSS=true, inline=false; Guest=false, Guest Optimization=false; JS minificado=true, combinado=false, diferido=1. Los valores guardados de combinado y Guest Optimization son 1, pero las barandas los neutralizan. No leer solamente `get_option()` para decidir el efecto.

UCSS de los archivos combinados requiere `$this->cfg_css_comb` en `litespeed-cache/src/optimize.cls.php:313`; no aparece UCSS inline en el HTML actual. No es evidencia de que UCSS esté quitando las reglas del trust.

El orden actual del head es `akibara-critical-inline-css` → core CSS bloqueante → WooCommerce → loop → home → trust, estos últimos con `media=all`, seguido por módulos secundarios y hero/series antes del cierre del head. `inc/enqueue.php:447` encola trust con dependencia `akibara-base`, alias de core. Los siete handles diferidos de `inc/performance.php:736` NO incluyen core/home/trust. El CSS crítico permanece inline en producción: LiteSpeed no lo ha movido a un recurso externo.

Los SHA256 de producción y local coinciden para el mu-plugin de barandas, `critical.css`, `core.min.css` y `trust.min.css`. No se identifica un despliegue parcial de esos archivos.

## Fragilidad confirmada, causa del destello aún pendiente

La home actual tiene 63 SVG y 10 carecen de width/height: 4 del trust post-hero, 3 del footer trust, 2 flechas ocultas del hero y 1 cierre del drawer inicialmente oculto. Los SVG comunes del header sí tienen dimensiones intrínsecas desde `inc/icons.php:32`.

Los cuatro SVG del trust post-hero provienen del `post_content` editorial de la página 210; no de `inc/trust-badges.php`, que pinta los tres del footer. En trust.css:82 se dimensionan a 16x16 en escritorio y trust.css:190 a 12x12 en móvil. Sin esa hoja, base.css:140 solo limita `max-width:100%;height:auto`, que no fija un tamaño de icono, y critical.css no contiene respaldo de tamaño. El comentario de base.css:135 reconoce un antecedente de SVG gigantes cuando falta CSS específico. Esto explica un mecanismo posible, pero no demuestra todavía que el destello observado sea causado por una carga fallida/demorada de trust.

## Caché y minificado: hipótesis separada

LiteSpeed vuelve a minificar hojas ya preminificadas: core publicado 62475 bytes contra 62710 de su fuente `.min` (235 bytes de ahorro); trust 2636 contra 2642 (6 bytes). Los artefactos pasan a `/wp-content/litespeed/css/<hash>.css`.

`Purge::_purge_all_cssjs()` en `litespeed-cache/src/purge.cls.php:482` purga LSCache y elimina los directorios CSS/JS; esa función no llama a Cloudflare. La purga completa `_purge_all()` sí llama a Cloudflare cuando están habilitados `cdn-cloudflare` y `cdn-cloudflare_clear`. Un HTML retenido en el borde o una pestaña vieja podría referir un hash borrado si solo se purga CSS/JS. Actualmente todas las referencias existen; no se reproduce esa ventana ni se identifica una purga concreta que explique el reporte.

## Control y corrección propuestos para QA

1. Medir en local baseline y carga fría con hoja trust retrasada/ausente y posterior recuperación, observar tamaños reales de esos cuatro SVG y el primer paint. Chrome puede esperar las hojas de head y ocultar un simple retraso; probar fallo recuperable y, si disponible, Firefox.
2. La orden del dueño del 7 de octubre exige corregir el responsable sin CSS redundante ni barandas nuevas. Las dimensiones intrínsecas pertenecen al marcado canónico de cada componente. Para los cuatro SVG editoriales, usar la UI oficial con preimagen/restauración editorial; no modificar el post por CLI en producción. La cascada responsive existente conserva los tamaños finales.
3. Evitar la doble minificación de archivos ya generados `.min.css` mediante la exclusión nativa de LiteSpeed es una candidata para la ventana demostrada abajo. Conserva la minificación del build y permite que LiteSpeed minifique fuentes que no traen build. Antes de una configuración productiva, verificar en local y luego QA en producción. No desactivar JS, UCSS o caché global.

Desarrollo implementa; QA independiente vuelve a medir local y producción y solo entonces cierra. La captura normal saludable no invalida el reporte intermitente del dueño.

## Prueba determinista de lifecycle, 2026-10-07

`output/home-css-20261006/local-cache-lifecycle.py` ejecuta el método real `_purge_all_cssjs(true)` mediante Reflection en el Docker local, después de guardar el HTML y fotografiar únicamente el estado de LiteSpeed. No llama a producción. La ejecución confirmó:

- HTML inicial: 16/16 URLs CSS optimizadas devuelven 200.
- Después de purgar CSS/JS, antes de volver a pedir home: 16/16 URLs del HTML guardado devuelven 404.
- Después de volver a pedir home: 16/16 URLs optimizadas, incluidas las antiguas, devuelven 200. Los hashes de archivo y parámetros de URL se recuperan iguales.
- Restauración: 215 options relacionadas con LiteSpeed, las tres tablas LiteSpeed y 51 archivos de caché coinciden exactamente con las preimágenes. Cron local volvió a arrancar.

Resultados: `local-cache-lifecycle-result.json`. Las preimágenes con posibles opciones privadas se conservaron fuera del repositorio bajo la configuración privada; no se publicaron sus valores. El primer intento falló por `/dev/stdin` inexistente para WP-CLI eval-file en el contenedor, sin ejecutar la purga; se corrigió a eval con `stream_get_contents(STDIN)` y se repitió la prueba. Esto confirma el mecanismo de URLs desaparecidas durante la regeneración, no que ese mecanismo haya provocado el destello del dueño en su visita concreta.

## Candidato de marcado local, pendiente de QA

Cambios exclusivamente en atributos HTML:

- `themes/akibara-gp/inc/trust-badges.php`: tres iconos footer, `width="16" height="16"`.
- `plugins/akibara-business/src/Modules/HomeHero/Renderer.php`: dos flechas, `width="22" height="22"`.
- `plugins/akibara-business/src/Modules/NativeCart/NativeCart.php`: cierre, `width="24" height="24"`.

Antes de editar, los tres archivos coincidían byte a byte por SHA256 con producción (`prod-canonical-markup-hashes-before.txt`). No se modificó CSS, crítico, barandas, versión, settings ni archivos minificados; no requieren rebuild. Los cuatro SVG editoriales están sin editar. Selector preciso para la UI: `.akb-trust-item__icon > svg` dentro de `.akb-trust-section` de la página 210, añadir `width="16" height="16"` a los cuatro; trust.css mantiene 12px en móvil y ocultación <=380px. Preimagen de su sección: `editorial-trust-before.html`.

Verificación local: tres PHP lints; tres pruebas existentes de trust a11y; diez RendererTest con 66 assertions; suite NativeCart de ARIA/foco/concurrencia/recuperación. El HTML local tiene ahora 63 SVG y solo los cuatro editoriales siguen sin dimensiones (antes diez). No desplegado.

## Cierre independiente del alcance publicado, 7-oct-2026

QA qa_rewards_final releyó producción después de despliegue/UI y purga puntual oficial de home. Gate final PASS: tres SHA PHP exactos, staging remoto ausente, página210 con sólo cuatro dimensiones SVG añadidas respecto de preimagen UI, home pública63/63SVG con dimensiones,16CSS principales200 (8canónicas/8LS),6alternativas noscript200 y26JS propios200/MIME/cuerpo correctos. Minificación CSS/JS permanece activa; único cambio de configuración LiteSpeed es exclusión .min.css. Los resúmenes avatar/cloud cambian como runtime. Rollback sólo local.

Se detectó y controló una copia pública anterior al despliegue, que desapareció tras purga puntual de root. No se presenta como prueba causal de la visita original del dueño. Se cierra entrega CSS preminificada/marcado SVG; el antecedente intermitente queda documentado sin causa definitiva acreditada. Informe completo y límites: output/home-css-20261006/qa-final-production-oct7.md. El carril de puntos sigue pendiente con gate previo revocado.
