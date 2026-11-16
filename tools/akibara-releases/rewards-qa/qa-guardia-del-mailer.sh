#!/usr/bin/env bash
# QA · ¿el guardia del mailer sobrevive a una petición web con el mailer sin cargar?
#
# POR QUÉ EXISTE: `AkibaraEmail::mailer_ready()` es estático en una clase que
# `extends WC_Email`. Llamarlo autocarga el fichero y el fatal salta AL DECLARAR
# la clase, antes de ejecutar el método. El guardia contra
# «Class "WC_Email" not found» PROVOCA «Class "WC_Email" not found».
# Medido en producción: acción 385728, akb_sf_digest_run, 2026-09-02 00:50:03.
#
# Esta comprobación TIENE que fallar hoy. Si pasa sin que nadie haya tocado el
# código, sospecha del instrumento antes que del sistema.
#
# Uso:  ./qa-guardia-del-mailer.sh            (espejo, http://localhost:8080)
#       BASE=http://otro:8080 ./qa-guardia-del-mailer.sh
set -u
BASE="${BASE:-http://localhost:8080}"
RAIZ="${RAIZ:-$(cd "$(dirname "$0")/../../.." && pwd)/public_html}"
SONDA="$RAIZ/qa-guardia-mailer-$$.php"

limpiar() { rm -f "$SONDA"; }
trap limpiar EXIT INT TERM

cat > "$SONDA" <<'PHP'
<?php
// Sonda efímera de QA. El trap del script la borra pase lo que pase.
require_once __DIR__ . '/wp-load.php';
header('Content-Type: text/plain; charset=utf-8');
printf("wc_presente=%s wc_email_precargada=%s\n",
    var_export(function_exists('WC'), true),
    var_export(class_exists('WC_Email', false), true));
// Se prueba el guardia que USAN los llamadores. Si ya existe MailerBoot es
// ese; si no, el viejo de AkibaraEmail. Así la comprobación sigue valiendo
// antes y después del arreglo, y dice cuál probó.
// class_exists() con autocarga DESACTIVADA: sólo mira si el fichero ya está
// resuelto, sin dispararlo. Preguntar con autocarga aquí provocaría el mismo
// fatal que queremos medir abajo, y el error saldría del sitio equivocado.
$hay_boot = file_exists(WP_PLUGIN_DIR . '/akibara-business/src/Services/MailerBoot.php');
printf("guardia_probado=%s\n", $hay_boot ? 'MailerBoot::ready' : 'AkibaraEmail::mailer_ready');
try {
    $r = $hay_boot
        ? \Akibara\Business\Services\MailerBoot::ready()
        : \Akibara\Business\Services\AkibaraEmail::mailer_ready();
    printf("RESULTADO=%s\n", var_export($r, true));
} catch (\Throwable $e) {
    printf("FATAL=%s|%s|%s:%d\n", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
}
PHP

salida="$(curl -s --max-time 30 "$BASE/$(basename "$SONDA")")"
limpiar; trap - EXIT INT TERM

echo "$salida"
echo "────────────────────────────────────────────"

if printf '%s' "$salida" | grep -q 'FATAL='; then
  echo "✗ FALLA — el guardia REVIENTA en vez de devolver false."
  echo "  Consecuencia: Action Scheduler marca la acción failed, no reintenta,"
  echo "  y el reagendado vive al final de run(), línea que nunca se alcanza:"
  echo "  la cola de avisos se queda parada hasta la próxima publicación."
  exit 1
fi

if printf '%s' "$salida" | grep -qE 'RESULTADO=(true|false)'; then
  if printf '%s' "$salida" | grep -q 'wc_email_precargada=true'; then
    echo "⚠ NO CONCLUYENTE — WC_Email ya estaba cargada en esta petición, así que"
    echo "  el guardia no se ha puesto a prueba. Esta comprobación sólo vale"
    echo "  cuando wc_email_precargada=false. No lo cuentes como verde."
    exit 2
  fi
  echo "✓ PASA — el guardia devuelve un booleano sin morirse, con el mailer sin cargar."
  exit 0
fi

echo "⚠ NO CONCLUYENTE — no reconozco la respuesta (¿la sonda no se sirvió?)."
exit 2
