# Empaquetado reproducible de componentes Akibara

Este tooling es exclusivamente local. No abre conexiones SSH ni modifica QA o
producción.

> **Carril vigente (2026-08-11).** Para código vivo usa los wrappers
> `package-business.sh`, `package-search.sh`, `package-checkout.sh` y
> `package-theme.sh`, todos respaldados por `package-runtime.sh`. El gate
> actual de Cart & Checkout Blocks es `docker-checkout.sh`. La promoción del
> runtime vivo sigue `AGENTS.md`: Docker local primero y conectores oficiales
> antes de cualquier carril SSH histórico. Los scripts que incluyen
> `business-blocks-production` en su nombre están sellados a releases
> históricos y no deben reutilizarse para el runtime vigente.

Las versiones vivas esperadas se declaran una sola vez en
`package-runtime.sh`: Business `1.103.2`, Search `0.1.17`, Cart & Checkout
Blocks `2.2.3` y theme `2.1.80`. El perfil `theme-production` y los scripts con
versiones anteriores se conservan únicamente como pruebas históricas; no son
un carril de promoción vigente.

## Contrato

- Akibara Business y Business SEO: versión exacta `1.103.2`.
- Akibara Search: versión exacta `0.1.17`.
- Akibara Cart & Checkout Blocks: versión exacta `2.2.3`.
- Akibara GP vivo: versión exacta `2.1.80`, con parent `generatepress`.
- Akibara GP `theme-production`: perfil histórico exacto `2.1.49`; no usar
  para promover el theme vivo.
- El ZIP contiene únicamente rutas runtime revisadas.
- `tests/`, dependencias, migraciones, respaldos, temporales, SQL, archivos de
  configuración de desarrollo y rutas superiores desconocidas abortan el
  empaquetado.
- Los permisos y timestamps se normalizan antes de comprimir.
- El orden de los archivos es estable, `zip -X` elimina metadatos extra y el
  modo store (`-0`) evita depender de una implementación de deflate.
- Cada release produce un inventario SHA-256 por archivo y un SHA-256 del ZIP.
- Un artefacto existente con el mismo nombre nunca se sobrescribe si sus bytes
  difieren.
- El gate local ejecuta también el contrato arquitectónico, PHP lint, sintaxis
  JavaScript, QA Search con smoke HTTP y pruebas Node del adaptador Cart &
  Checkout Blocks.
- Las regresiones históricas de theme se prueban por separado; no determinan
  la versión viva de Checkout ni sustituyen sus gates propios.

## Pruebas

```bash
bash tools/akibara-releases/test-packages.sh
bash tools/akibara-business/test-package.sh
bash tools/akibara-releases/qa-search.sh
bash tools/akibara-releases/qa-terminology.sh
bash tools/akibara-releases/docker-checkout.sh
bash tools/akibara-releases/qa-theme.sh            # ZIP de la versión viva del tema + suite actual
bash tools/akibara-releases/qa-theme-production-superset.sh   # ZIP congelado 2.1.49: bytes e invariantes
bash tools/akibara-releases/verify-theme-production-superset.sh
```

`test-packages.sh` cubre `business`, `search`, `checkout`, `rewards` (desde el 2026-09-19: hasta entonces
ese perfil existía en `package-runtime.sh` y ningún gate lo ejercitaba), `theme` y `theme-production`.
Con `theme` (o sin `--component`) también construye un ZIP del árbol en un temporal y le pasa
`qa-theme.sh --candidate-dir` entero (desde el 2026-09-23: el gate llevaba rojo sin que nada lo corriera).

`qa-theme.sh` toma la `Version:` de `style.css` (o la que se le pase) y prueba
el ZIP publicado en `dist/` (o el de `--candidate-dir RUTA`), no el árbol:
extrae también los ZIP de Checkout Blocks, Search y Business en las versiones
de sus cabeceras, porque tres pruebas del tema leen esos plugins por ruta
relativa. Lo extrae con la estructura del repo (`public_html/wp-content/…`) y
copia de `tools/` lo que las pruebas importan (hoy `build-theme-min-assets.mjs`,
que min-assets.test.js usa para recalcular la huella de cada copia `.min`); si
una prueba nueva importa otra cosa de `tools/`, el gate lo nombra y para. Los
gates de artefactos congelados (2.1.49 superset, 2.1.50 SEO) ya no corren las
pruebas del árbol actual, que describen otro tema: validan bytes, inventario y
sintaxis.

La prueba construye los tres plugins y ambos perfiles de theme dos veces bajo
zonas horarias opuestas, compara ZIP e inventarios byte a byte, extrae los ZIP,
verifica todos los hashes y comprueba que las guardas rechacen un temporal,
AppleDouble, una ruta superior no revisada y un enlace simbólico.

## Construcción

```bash
bash tools/akibara-releases/package-business.sh
# Alias explícito del mismo release Business-only:
bash tools/akibara-releases/package-business-seo.sh
bash tools/akibara-releases/package-search.sh
bash tools/akibara-releases/package-checkout.sh
bash tools/akibara-releases/package-theme.sh
bash tools/akibara-releases/package-theme-production.sh
```

Salidas:

- `dist/akibara-business-1.103.2.{zip,files.sha256,zip.sha256}`
- `dist/akibara-search-0.1.17.{zip,files.sha256,zip.sha256}`
- `dist/akibara-cart-checkout-blocks-2.2.3.{zip,files.sha256,zip.sha256}`
- `dist/akibara-gp-2.1.80.{zip,files.sha256,zip.sha256}`
- `dist/akibara-gp-2.1.49.zip`
- `dist/akibara-gp-2.1.49.files.sha256`
- `dist/akibara-gp-2.1.49.zip.sha256`

Para validar sin publicar archivos:

```bash
bash tools/akibara-releases/package-business.sh --verify-only
# Alias explícito del mismo release Business-only:
bash tools/akibara-releases/package-business-seo.sh --verify-only
bash tools/akibara-releases/package-search.sh --verify-only
bash tools/akibara-releases/package-checkout.sh --verify-only
bash tools/akibara-releases/package-theme.sh --verify-only
bash tools/akibara-releases/package-theme-production.sh --verify-only
```
