# Código, datos y entrega

El espejo contiene reseñas, pedidos y otros datos de prueba. La promoción sólo admite paquetes de runtime; nunca importa su base de datos ni copia uploads, options, clientes, ledger, inventario o sesiones. La prueba de una reseña no es contenido publicable.

## Git

Los commits locales anteriores a esta entrega incluyen respaldos y datos privados. No se deben publicar ni usar como padres de una rama compartida. La rama limpia parte del `origin/main` comprobado, exportando una lista explícita de fuentes con `python3 tools/export-quality-source.py DESTINO_NUEVO`. Se verifica la revisión exportada antes de publicarla. El checkout de trabajo original se conserva.

Ejecuta `bash tools/install-quality-hooks.sh` en cada clon. El hook pre-push inspecciona todos los commits nuevos y bloquea rutas privadas y firmas de secretos sin imprimir su contenido. Está instalado en el checkout local de esta entrega. `.gitignore` evita añadir datos nuevos por accidente, pero no limpia archivos ya rastreados ni su historial; el pre-push también revisa ese historial.

El control no sustituye la revisión: los secretos sin una firma reconocible requieren inspección. No se deben incluir credenciales en código o fixtures. CI vuelve a comprobar la revisión, instala dependencias bloqueadas, recompila assets, ejecuta pruebas y conserva paquetes para revisión. CI no recibe credenciales de producción ni despliega automáticamente.

Las entradas de terceros se materializan desde [un lock de origen, versión y SHA-256](../../tools/ci-dependencies/README.md). No se versiona una copia del espejo ni se usa `latest` para seleccionar proveedores. El pipeline comprueba PHP mínimo soportado 8.1 y PHP productivo 8.5.12, y fija Composer 2.11.1, el compilador y las revisiones de las acciones. Una actualización de producción requiere revisar estos pins y sus contratos. Los directorios de terceros materializados se excluyen de Git; nunca son paquetes propios de despliegue.

El punto de entrada de compilación es `npm ci --no-audit --no-fund` seguido de `npm run build`. El lock fija esbuild 0.28.2; el mismo binario verificado genera tema y los cuatro bundles de Search. El gate comprueba hashes de fuentes y bytes publicados, por lo que editar un fuente sin reconstruir su minificado bloquea la entrega.

## Antigravity

La política se carga desde `AGENTS.md` y dos archivos nativos `always_on` en `.agents/rules/`. El catálogo conserva una única fuente, sin copias por modelo. Se separó de AGENTS.md para respetar el límite oficial de 24.000 bytes por archivo; CI verifica existencia, activación y tamaño. La instalación local observada es Antigravity 2.28.2. Estas reglas del proyecto aplican al modelo seleccionado; no fijan nombres de modelos que la aplicación no ofrezca.

La carga de reglas sigue la [documentación oficial de Antigravity](https://antigravity.google/docs/rules/). La configuración global del usuario también puede aportar reglas; los controles de Git, build, paquetes y CI siguen siendo necesarios. Las instrucciones orientan al agente y los controles automáticos validan lo que se entrega. Ninguno sustituye el QA independiente en producción.

## Producción

`package-runtime.sh` y `verify-runtime-package.py` sellan y verifican el contenido permitido. El carril de MU respeta los componentes locales en cuarentena y mantiene sincronizado el transporte SMTP con su recurso de rescate fuera del docroot. Los respaldos de recuperación se descargan únicamente a local.

Secuencia: espejo válido, pruebas y QA independiente, respaldo local, paquete verificado, despliegue, purga y medición pública productiva, QA independiente y actualización final del grafo. Un deploy técnico aprobado no sustituye esa medición pública. Cada cambio de inventario necesita su propio plan derivado de producción y nunca del espejo.
