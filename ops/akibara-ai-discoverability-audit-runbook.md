# Auditoría read-only de descubribilidad en asistentes IA

La herramienta `tools/akibara-ai-discoverability-audit.sh` comprueba que la
superficie pública de Akibara siga siendo rastreable y entendible por los
asistentes y buscadores más usados. Es una prueba técnica de elegibilidad: **no
garantiza rankings, citas ni recomendaciones** de ChatGPT, Gemini, Claude,
Perplexity o Google.

## Alcance y seguridad

El auditor es estrictamente de solo lectura:

- únicamente realiza solicitudes HTTP `GET` a recursos públicos;
- usa redirecciones manuales: nunca sigue un `Location`; cualquier `3xx` queda
  registrado como fallo HTTP y como redirección directa;
- no inicia sesión, no lee variables de credenciales, no usa cookies y no
  llama a WordPress, Merchant Center, Search Console ni Cloudflare con APIs;
- no cambia producción ni crea respaldos remotos;
- guarda cuerpos públicos sólo durante la ejecución en un directorio privado
  creado con `mktemp -d`, `umask 077`, y lo elimina mediante `trap`;
- emite un único documento JSON por `stdout`; el progreso va por `stderr`.

`--base-url` siempre define la identidad canónica esperada en canonical,
JSON-LD, `robots.txt`, `llms.txt` y sitemaps. `--fetch-base-url` cambia sólo el
destino de los `GET`; esto permite consultar Docker local sin aceptar URLs
locales como canónicas ni debilitar el gate semántico.

La auditoría valida:

1. HTTP 200 en `/`, `/manga/`, `/nosotros/`, `/contacto/`, `/faq/`,
   `/terminos-y-condiciones/`, `/robots.txt`, `/llms.txt` y
   `/sitemap_index.xml` usando 12 agentes:
   OAI-SearchBot, ChatGPT-User, GPTBot,
   PerplexityBot, Perplexity-User, ClaudeBot, Claude-SearchBot, Claude-User,
   Googlebot, Googlebot-Image, Storebot-Google y bingbot.
2. `robots.txt`: acceso a las rutas críticas para los crawlers que obedecen el
   REP, declaración del sitemap canónico y evaluación separada de
   `Google-Extended`, que no emite un user-agent HTTP propio.
3. Flujo Merchant: `Storebot-Google` debe poder recorrer `/manga/`, carrito y
   checkout. Un wildcard que bloquee ese recorrido hace fallar el gate.
4. `Content-Signal` se valida de forma independiente en la directiva efectiva
   de `robots.txt` y en el header HTTP. `ai-train` requiere un contrato
   explícito por CLI; no se infiere desde el objetivo comercial.
5. Negociación `Accept: text/markdown`: informa si Cloudflare Markdown for
   Agents está deshabilitado (`skip`) o exige Markdown, `Vary: Accept` y
   `Content-Signal` coherentes cuando está activo.
6. `/manga/`: title exacto, meta description esperada, canonical limpia y
   ausencia de `noindex` tanto en HTML como en `X-Robots-Tag`.
7. JSON-LD: la home debe tener una única entidad de tipo Organization o
   subtipo, la canónica `/#organization`, tipada a la vez como `OnlineStore` y
   `Organization`. En manga, nosotros, contacto y FAQ, la ausencia de schema de
   organización es válida; si existe, debe haber exactamente una entidad y debe
   reutilizar esa misma canónica, sin una Organization competidora. Se
   normalizan términos compactos, IRIs de schema.org y aliases declarados en
   `@context`.
8. JSON-LD físico: las cinco superficies deben omitir `address`,
   `legalAddress`, campos postales, `Place`/subtipos, `location`, `geo`, mapas,
   puntos de venta, horarios y `LocalBusiness` o cualquiera de sus subtipos
   conocidos. También se inspeccionan los valores string por señales de
   storefront, dirección o mapa. Los enlaces de mapa cubren Google, Apple,
   Waze, Bing, OpenStreetMap y MapQuest. Los iconos de ubicación renderizados
   se detectan por atributos semánticos, aunque no exista un enlace. La
   evidencia sólo conserva página, nodo,
   propiedades, tipos normalizados y nombres de señales; nunca copia esos
   valores.
9. `/nosotros/`: sólo su contenido dentro de `<main>` puede satisfacer las
   señales positivas. Debe declarar tienda 100 % online, ausencia de local
   físico y atención espontánea, retiro coordinado asociado a San Miguel y
   dirección entregada sólo después de confirmar. Las señales negativas se
   revisan en todo el `<body>` para que header o footer no oculten una dirección,
   mapa, `visítanos`, `retiro presencial` o copy contradictorio. La negación
   inequívoca «sin local abierto al público» se interpreta como ausencia de
   storefront, no como una afirmación positiva.
10. Home, manga, nosotros, contacto y FAQ: header, contenido principal y footer
   omiten dirección pública, enlace/icono de mapa y afirmaciones de local
   físico.
11. `/terminos-y-condiciones/`: exige razón social, RUT, domicilio **legal**,
   representante y canal de contacto. El domicilio debe incluir una dirección,
   estar rotulado como dato legal y negar expresamente que sea local, sucursal o
   punto de retiro público. Un domicilio rotulado sólo como tributario/SII no
   satisface el gate. La evidencia emite únicamente booleanos; nunca copia RUT,
   nombre del representante ni dirección. El auditor valida el contenido
   publicado, pero la coincidencia del domicilio con estatutos o certificado
   societario vigente sigue siendo un gate humano previo a la UI.
12. `MerchantReturnPolicy`: opcional por defecto; si aparece, debe estar adjunta
   a la organización y coincidir con el contrato completo (Chile, ventana de
   10 días, enlace público, devolución por correo, reembolso total, costo por
   arrepentimiento y costo cero por defecto). Se puede hacer obligatoria o
   cambiar el número esperado por CLI.
13. `llms.txt`: HTTP 200, contenido estable y referencia exacta a `/manga/`.
   La ausencia del enlace es advertencia por defecto, porque `llms.txt` no es
   un estándar de ranking.
14. Sitemap index y **todos** sus hijos: mismo origen, HTTP 200, XML bajo los
    12 user agents y presencia exacta de `/manga/`.

Referencias oficiales para la matriz:

- [OpenAI Crawlers](https://developers.openai.com/api/docs/bots)
- [Perplexity Crawlers](https://docs.perplexity.ai/docs/resources/perplexity-crawlers)
- [Anthropic Bots](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)
- [Google crawlers and fetchers](https://developers.google.com/crawling/docs/crawlers-fetchers/overview-google-crawlers)
- [Google StoreBot](https://support.google.com/merchants/answer/13294660?hl=en)
- [Google Organization structured data](https://developers.google.com/search/docs/appearance/structured-data/organization)
- [Google Business eligibility and ownership](https://support.google.com/business/answer/13763036?hl=en)
- [Schema.org LocalBusiness](https://schema.org/LocalBusiness)
- [Reglamento de Comercio Electrónico, Decreto 6, artículo 7](https://www.bcn.cl/leychile/navegar?idNorma=1165504)
- [Cloudflare Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/)

## Políticas de robots preparadas

Hay dos cambios distintos y no deben publicarse juntos por accidente:

- `contracts/rankmath-robots-v14-storebot-only-candidate.txt` agrega sólo un
  grupo específico de `Storebot-Google`, abre carrito, checkout y
  `/wp-json/wc/store/`, y conserva `ai-train=yes` para todos los agentes. Es el
  cambio mínimo compatible con la política pública v13.
- `contracts/rankmath-robots-v14-selective-candidate.txt` además bloquea bots de
  entrenamiento cuando el proveedor permite separarlos. Sigue pendiente de una
  decisión expresa del dueño porque `Google-Extended` controla conjuntamente
  grounding en Gemini Apps y posible entrenamiento futuro.

Ambos candidatos se validan con el mismo rail de UI oficial de Rank Math. El
modo Storebot-only rechaza cualquier grupo adicional o cambio de
`Content-Signal`; así evita convertir una corrección de Merchant Center en un
cambio implícito de política de entrenamiento.

## Ejecución recomendada

Desde la raíz del proyecto:

```bash
tools/akibara-ai-discoverability-audit.sh > /tmp/akibara-ai-audit.json
audit_exit=$?
jq '.summary, (.checks[] | select(.status != "pass"))' /tmp/akibara-ai-audit.json
printf 'exit=%s\n' "$audit_exit"
```

El archivo de salida del ejemplo es local; no debe subirse al repositorio. La
herramienta no necesita variables de entorno ni credenciales.

Para validar en Docker una réplica que debe seguir declarando la identidad
canónica de producción:

```bash
tools/akibara-ai-discoverability-audit.sh \
  --base-url https://akibara.cl/ \
  --fetch-base-url 'http://<servicio-interno>/' \
  --sitemap-ua-scope primary \
  > /tmp/akibara-ai-audit-docker.json
```

Las solicitudes críticas y los hijos declarados por el sitemap se obtienen
desde el destino local conservando sus paths. Todas las comparaciones de
canonical, entidad, enlaces y origen de sitemap siguen usando
`https://akibara.cl/`. Si la réplica intenta redirigir a esa URL pública, el
auditor no la sigue: registra el `3xx` y falla cerrado, evitando confundir una
respuesta de producción con evidencia local.

Después de publicar el texto verdadero de `/nosotros/`, la entidad canónica sin
señales de ubicación física, `llms.txt` y el schema de devoluciones, conviene
usar el contrato estricto:

```bash
tools/akibara-ai-discoverability-audit.sh \
  --require-return-policy \
  --require-llms-manga \
  --expected-ai-train yes \
  --strict \
  > /tmp/akibara-ai-audit-strict.json
```

Para una comprobación rápida, los hijos de sitemap pueden pedirse sólo con
OAI-SearchBot. Los ocho recursos críticos siempre se prueban con los 12 user
agents:

```bash
tools/akibara-ai-discoverability-audit.sh \
  --sitemap-ua-scope primary \
  > /tmp/akibara-ai-audit-quick.json
```

## Contrato de salida

| Exit code | Estado JSON | Significado |
|---:|---|---|
| `0` | `pass` | Pasaron todas las comprobaciones obligatorias y no hay advertencias |
| `1` | `fail` | Falló al menos una comprobación; corregir antes de usarlo como gate |
| `2` | `warn` | No hay fallos, pero queda al menos una advertencia |

El JSON incluye:

- `read_only: true` y `ranking_guarantee: false`;
- `base_url` como identidad canónica y `fetch_base_url` como destino HTTP
  efectivo;
- valores esperados no sensibles, modelo comercial y modo efectivo;
- resumen contable;
- cada check con `id`, `status`, mensaje y evidencia acotada;
- cada respuesta fallida identifica el `user_agent` simulado que la recibió;
- hashes SHA-256 de respuestas cuando ayudan a detectar contenido distinto por
  user agent, sin conservar los cuerpos HTML después de terminar.

El schema de salida `3` elimina la configuración y evidencia de calle/código
postal del contrato anterior. Los reportes históricos con schema `2` se
conservan sin modificación como evidencia del estado público observado en su
fecha; no deben normalizarse ni usarse como aprobación del contrato actual.

Un `warn` por meta description o enlace de `llms.txt` es esperado antes de
publicar esos valores. La ausencia del header `Content-Signal` aparece como
`skip` mientras no se active `--require-content-signal`; eso no significa que
falte la directiva independiente de `robots.txt`. Si no se entrega
`--expected-ai-train yes|no`, el auditor genera una advertencia de gobernanza y
`--strict` la convierte en fallo. El gate estricto debe declarar esa decisión.

## Opciones configurables

```text
--base-url URL
--fetch-base-url URL
--expected-title TEXTO
--expected-meta TEXTO
--expected-pickup-locality TEXTO
--expected-return-days N|any
--expected-ai-train yes|no
--require-content-signal
--require-return-policy
--require-llms-manga
--strict
--timeout-ms N
--concurrency N
--max-sitemaps N
--sitemap-ua-scope all|primary
```

`--base-url` y `--fetch-base-url` admiten sólo `http` o `https` y rechazan URLs
con usuario o contraseña. Si `--fetch-base-url` se omite, toma exactamente el
valor de `--base-url`, preservando el comportamiento anterior. Para producción
se debe mantener `--base-url https://akibara.cl/`; al auditar producción también
se omite `--fetch-base-url` o se usa ese mismo valor.

## Lectura de fallos

- `http.*`: bloqueo, timeout o cualquier estado distinto de 200. Los `3xx` se
  registran con `redirected: true` y nunca se siguen, incluso cuando apuntan a
  la base canónica pública.
- `robots.*`: una regla aplicable bloquea una ruta crítica o falta la directiva
  `Sitemap:`, Storebot no puede recorrer el flujo comercial, la directiva
  `Content-Signal` es inválida o `ai-train` no coincide con el contrato.
- `representation.markdown_for_agents`: negociación Markdown parcial o activa
  sin `Vary: Accept`/`Content-Signal` coherente.
- `manga.*`: metadatos divergentes, canonical incorrecta o `noindex`.
- `schema.canonical_online_store`: home no tiene exactamente una entidad
  `/#organization` con `OnlineStore` y `Organization`, o una superficie
  secundaria que publica schema de organización no reutiliza esa única entidad.
- `schema.online_store_without_physical_location`: alguna superficie comercial
  publica una propiedad o tipo JSON-LD de dirección, lugar, geolocalización,
  punto de venta, mapa, horario o local físico, incluido un subtipo conocido de
  `LocalBusiness` o una señal física dentro de un valor string. La evidencia
  identifica sólo página, nodo, claves, tipos normalizados y nombres de señal;
  nunca copia el valor observado.
- `content.about_online_store_truth`: el `<main>` de `/nosotros/` no reúne las
  señales positivas, no vincula el retiro con la comuna esperada, o cualquier
  parte visible de su body conserva una ubicación/copy físico contradictorio.
- `content.public_surfaces_without_physical_location`: home, manga, nosotros,
  contacto o FAQ publica en header, cuerpo o footer una dirección, enlace o
  icono de mapa, o afirmación de storefront.
- `content.terms_seller_legal_identity`: Términos omite razón social, RUT,
  domicilio legal, representante o contacto; usa sólo el rótulo tributario;
  deja placeholders; o presenta el domicilio como local, sucursal, retiro
  público o destino de mapa. El control no certifica la fuente societaria: esa
  comprobación documental sigue siendo obligatoria antes de guardar en la UI.
- `schema.merchant_return_policy`: política ausente en modo obligatorio,
  inconsistente por user agent o con días distintos al contrato.
- `sitemap.*`: índice/hijo no XML, hijo externo, respuesta distinta de 200 o
  `/manga/` ausente.

El auditor comprueba disponibilidad y semántica pública, no autenticidad del
bot, indexación real ni preferencia algorítmica. Los user-agents son headers
simulados desde una IP del auditor. Cloudflare Security Events/AI Crawl Control,
rangos IP o rDNS oficiales, Search Console, Merchant Center y pruebas manuales
de respuesta en asistentes siguen siendo gates separados.

Por esa razón, un 403/429 obtenido al simular un bot no demuestra que el bot
auténtico esté bloqueado: Cloudflare puede estar rechazando correctamente un
User-Agent falsificado desde una IP no verificada. Antes de crear excepciones de
WAF hay que contrastar el evento con identidad de bot verificada y logs reales.
