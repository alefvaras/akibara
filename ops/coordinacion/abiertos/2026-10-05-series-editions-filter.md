# El filtro de otras ediciones no reduce la lista

- Estado: resuelto por QA independiente en local y producción
- De: reviews_audit (desarrollo), coordinación root
- Para: root y reviews_qa
- Archivos: public_html/wp-content/plugins/akibara-business/src/Modules/SeriesEditions/SeriesEditions.php; tests/Modules/SeriesEditions/series-editions-picker.test.mjs

El dueño observa que escribir un nombre (por ejemplo tok/Naruto) deja series ajenas visibles. Control antes de arreglar: `render_editions_picker` imprime el script antes del marcado del selector; el IIFE consulta `document.querySelectorAll` cuando esa lista aún no existe. La regla CSS `[hidden] { display:none }` sí está presente, por lo que no falta ocultación sino inicialización. Confirmado leyendo el orden real del HTML emitido.

Solución delimitada: inicializar al DOM disponible y después del marcado, con guarda por selector contra listeners repetidos. Filtrar sólo cambia visibilidad: casillas elegidas, cuenta y relaciones no se alteran. Casos locales: parseo/loading, documento ya listo, consulta con mayúsculas/acentos, sin coincidencias, vaciar, seleccionada oculta conserva checked. No guardar series en la comprobación de UI.

Desarrollo no cierra su arreglo. Root despliega sólo archivo exacto después del gate local y QA re-mide producción. No despliegue del plugin completo por cambios ajenos en el árbol.


Desarrollo local listo: assets emitidos después del marcado, init espera DOMContentLoaded si se está parseando y se ejecuta inmediatamente si ya está listo; data-akb-editions-ready evita duplicar listeners.

- Control negativo: los dos tests JS nuevos contra el script original HEAD fallan; búsqueda Naruto deja From Hell y Tókyō Ghoul visibles, y ejecución repetida registra dos listeners.
- Arreglo: dos tests JS verdes (script real ejecutado en DOM simulado), PHP SeriesEditionsTest 26 pruebas /56 aserciones verdes; PHPCS verde.
- CUA local sobre edit-tags pa_serie: entrada Naruto muestra solamente Naruto y Naruto Jump Remix; ninguna relación guardada. Intentos posteriores de selección fueron interrumpidos por timeout CDP y no sirven como prueba visual de checked; su conservación está verificada por regresión JS. Term633 no existe en espejo; el módulo de alta usa el mismo selector. QA avisado para revisión independiente y producción.


## Cierre independiente QA

`qa_cierre` vuelve a medir en producción después del despliegue exacto de SeriesEditions.php, sin publicar relaciones: Naruto reduce a dos opciones; tok a Tokyo Ghoul/Tokyo Revengers; sin coincidencias oculta todas; vaciar devuelve114/114. La opción631 ya guardada permanece marcada cuando está oculta y la relación persistida sigue[631]. Hash remoto y local idénticos. Directorio temporal remoto ausente. Evidencia `output/reviews-admin-20261005/qa-final-production.json` y `qa-production-series-naruto.jpg`.
