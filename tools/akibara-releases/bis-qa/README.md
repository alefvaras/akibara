# QA de «Avísame cuando llegue» (BackInStock)

Sondas de Playwright del módulo. Todas marcan sus peticiones con user-agent
`AkibaraQA` para no contaminar el registro de búsquedas de clientes.

`node_modules` es un enlace a `../rewards-qa/node_modules` (ahí vive Playwright).

| Script | Dónde | Qué prueba |
| --- | --- | --- |
| `qa-bis-produccion.mjs` | producción | El formulario en fichas agotadas y NO en disponibles, móvil (44px), teclado, foco, y las rutas críticas de la tienda. Sólo lee. |
| `qa-bis-flujo-produccion.mjs` | producción | Alta real pulsando el botón. **Siembra una fila**: bórrala por `id`, nunca por email. |
| `qa-bis-flujo-cliente-local.mjs` | espejo | El carril entero: alta, correo, restock, aviso y baja. 44 comprobaciones. |
| `qa-bis-serie-varios-tomos-local.mjs` | espejo | El coleccionista que se anota a varios tomos de una serie (el caso que el tope de altas cortaba en el cuarto). |
| `qa-bis-movil-confirmacion-local.mjs` | espejo | Que la confirmación se vea en móvil sin buscarla, y que el foco aterrice ahí. |

Las pruebas de PHP del módulo están en `tools/test-bis-*.php` y se ejecutan
copiándolas al docroot (Docker sólo monta `public_html`):

```
cp tools/test-bis-panel.php public_html/_t.php
docker compose --profile tools run --rm -T cli wp eval-file _t.php
rm public_html/_t.php
```
