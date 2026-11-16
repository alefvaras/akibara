# Harness hermético del benchmark de asistentes

Este directorio prepara y valida la evidencia de las fases `B0`, `T0`, `T7`,
`T14` y `T30`. No abre navegadores, no usa red y no toca producción. Lee las 18
consultas canónicas desde `ops/ai-discoverability-prompt-benchmark.md` y crea la
matriz completa de cuatro motores por tres repeticiones: 216 corridas por fase.

`run.yaml` se serializa como JSON UTF-8. JSON es un subconjunto válido de YAML
1.2, con lo cual no se necesita PyYAML y no hay interpretación ambigua de
booleanos o fechas.

## Flujo

Inicializar una fase en una raíz local privada y vacía:

```sh
python3 tools/ai-discoverability-benchmark/benchmark.py init \
  --root .local-reports/ai-assistant-benchmark \
  --phase B0 \
  --batch-id 20260806-1200
```

El comando crea `B0/runs/<run_id>/` 216 veces. Cada corrida incluye, desde el
inicio, `run.yaml`, `response.md`, `cited-urls.txt`, `screenshot.png` y
`verification.json`, todos con modo `0600`; los directorios usan `0700`. El
`run_status` inicial es `prepared` y el sello empieza en `pending`.

Por cada ejecución real:

1. conservar exactamente la consulta de `run.yaml`;
2. completar `model_or_mode`, `web_mode`, `search_toggle_state`,
   `account_locale`, `location_context`, `completion_signal`,
   `started_at_chile` y `completed_at_chile`;
3. certificar el aislamiento y asignar uno de los dos estratos comparables:
   `clean` exige `temporary|incognito` con personalización `disabled`, mientras
   que `account_stratified` exige `temporary|incognito` con personalización
   `enabled`;
4. cambiar `run_status` a `completed` sólo después de observar la señal de fin;
5. guardar la respuesta completa en UTF-8 en `response.md`;
6. guardar una URL HTTP(S) por línea en `cited-urls.txt` y copiar la misma lista
   a `evidence.cited_urls`;
7. guardar la captura real como `screenshot.png`;
8. restaurar `chmod 600` si el editor cambió permisos;
9. sellar la corrida.

```sh
python3 tools/ai-discoverability-benchmark/benchmark.py seal-run \
  --run-dir .local-reports/ai-assistant-benchmark/B0/runs/20260806-1200-chatgpt-C01-1
```

`seal-run` rechaza `prepared`, `submitted` e `invalid`. También exige que
`completed_at_chile` sea igual o posterior a `started_at_chile` y que ambas
marcas usen el offset chileno `-03:00` o `-04:00`. Primero reemplaza
atómicamente `run.yaml` con `run_status=verified`; sólo después calcula el
SHA-256, tamaño y modo de los cuatro artefactos. Por eso el hash certifica el
estado final y no el borrador `completed`.

`fresh_only`, aislamiento `unknown` o personalización `unknown` producen el
estrato `invalid`: esa ejecución se conserva fuera de `runs/` como intento
inválido, o permanece dentro sin sello e impide finalizar la fase. Nunca se
convierte en una de las 216 corridas verificadas. Para corregir evidencia ya
sellada hay que volver de forma explícita `run_status` a `completed` y el sello
de `verification.json` a `pending`; no existe resellado silencioso.

Cuando estén completas las 216 corridas:

```sh
python3 tools/ai-discoverability-benchmark/benchmark.py finalize \
  --root .local-reports/ai-assistant-benchmark --phase B0

python3 tools/ai-discoverability-benchmark/benchmark.py validate \
  --root .local-reports/ai-assistant-benchmark --phase B0
```

`finalize` exige la matriz exacta, sin corridas faltantes o adicionales, y
escribe `manifest.json` con modo `0600`. El manifiesto usa rutas relativas,
orden estable, el hash del protocolo y ningún timestamp de generación; con la
misma evidencia vuelve a producir exactamente los mismos bytes. `validate` es
estrictamente de sólo lectura y compara ese manifiesto byte a byte.

## Pruebas

```sh
tools/ai-discoverability-benchmark/test.sh
```

Las pruebas usan únicamente directorios temporales y comprueban cardinalidad,
IDs, consultas, fase, permisos, PNG, transición atómica `completed→verified`,
estratos, estados inválidos, cronología, offsets, manipulación de hashes y
reproducibilidad del manifiesto.
