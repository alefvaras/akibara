#!/usr/bin/env bash
#
# Rescate del buscador cuando el rebuild se queda atascado.
#
# POR QUÉ EXISTE: el 2026-09-05 el buscador se atascó dos veces en producción y
# costó ~3 h levantarlo, y el 2026-09-06 se reprodujo en el espejo y se comprobó
# que **ninguna orden del propio plugin lo desatasca**:
#
#   wp akb-search rebuild    -> «Ya hay un rebuild en curso»
#   run_sync()               -> null en 0.00 s
#   wp akb-search reconcile  -> «ok»: no mira que el índice vaya por detrás
#
# EL ORDEN ES TODA LA RESPUESTA. Esta versión invierte los dos primeros pasos
# respecto de la del 2026-09-05, y no es un detalle de estilo:
#
#   Con la option BORRADA y las tablas `_new` todavía presentes, el sistema queda
#   en `ready_swap` sin dueño. En ese estado, el PRIMER arranque de WordPress
#   —una visita cualquiera, o el propio `wp akb-search rebuild` del de guardia—
#   acuña un estado BLOCKED con `orphan_topology_without_state`
#   (RebuildJob::handle_recovery() -> persist_orphan_topology_block()).
#   Medido: apareció solo a los ~40 s, sin teclear nada.
#
#   Y ese bloqueo es un PESTILLO PERMANENTE: `recovery_decision()` devuelve
#   'idle' para BLOCKED, `resume_run()` no tiene rama para él y
#   `schedule($force)` sale ante fase terminal —o sea que el botón
#   «Reintentar» del panel tampoco—. Y el watchdog, con el pestillo puesto, NO
#   pide rebuild: `schedule_request()` devuelve cadena vacía mientras exista la
#   fila de estado, así que nadie podría consumirlo (S-8ab53cfb, 2026-09-19;
#   antes lo pedía igual cada 15 min y llenaba el cuaderno de entradas falsas).
#   Lo que SÍ sigue vivo sin persona es lo que no depende de ningún run: la
#   reparación barata (`reconcile_now()`) y la ALARMA por correo, que
#   `maybe_recover_serving()` lleva en todas las vueltas. El día que se escribió
#   este guion ni eso: el watchdog sólo miraba el serving con el estado NULO.
#   El rebuild lo arranca ESTE guion al final (paso 4); no hay que esperar a nadie.
#
#   (Las funciones de RebuildJob van citadas por NOMBRE a propósito. Esta
#   cabecera citaba líneas de src/RebuildJob.php y en dos semanas estaban todas
#   corridas, hasta 170 líneas: a las 3 de la mañana una cita que apunta a otra
#   función es peor que ninguna. S-3e1f3f03, 2026-09-19.)
#
#   Medido en las dos direcciones:
#     sólo soltar la sombra, dejando el BLOCKED   -> clean_pre + BLOCKED: sigue atascado
#     sólo borrar la option, con la sombra puesta -> vuelve a BLOCKED al primer comando
#     sombra PRIMERO, option DESPUÉS              -> única combinación que sale
#
# El estado de reposo sano —el que producción tiene ahora mismo— es
# **option AUSENTE + topología `clean_pre`**. No una fase terminal: escribir
# PHASE_ABORTED deja el mismo pestillo con otro nombre (medido).
#
# NO sustituye al arreglo de fondo (ops/coordinacion/abiertos/2026-09-06-*):
# es la manguera mientras llega el extintor.
#
#   bash ops/rescate-buscador.sh espejo
#   bash ops/rescate-buscador.sh produccion     # pide confirmación escrita
#
set -Eeuo pipefail

destino="${1:-}"
case "${destino}" in
	espejo)
		wp() { docker compose run --rm --no-deps -T cli wp "$@"; }
		;;
	produccion)
		printf 'Vas a rescatar el buscador de PRODUCCIÓN (akibara.cl).\n'
		printf 'Durante el rebuild, suggest y facetas pueden servir degradado.\n'
		printf 'Escribe SI para continuar: '
		read -r ok
		[[ "${ok}" == 'SI' ]] || { echo 'Cancelado.'; exit 1; }
		wp() { ssh akibara "cd ~/domains/akibara.cl/public_html && /usr/local/bin/wp $*"; }
		;;
	*)
		echo 'Uso: bash ops/rescate-buscador.sh espejo|produccion' >&2
		exit 2
		;;
esac

# La topología se lee por SQL a propósito: `wp db query` no arranca WordPress, así
# que sirve incluso con el plugin caído y no puede acuñar nada por el camino.
topologia() {
	local nuevas viejas
	nuevas=$(wp db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('wp_akb_search_docs_new','wp_akb_search_tokens_new','wp_akb_search_facet_index_new');" --skip-column-names 2>/dev/null | tr -dc '0-9')
	viejas=$(wp db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('wp_akb_search_docs_old','wp_akb_search_tokens_old','wp_akb_search_facet_index_old');" --skip-column-names 2>/dev/null | tr -dc '0-9')
	printf 'new=%s old=%s' "${nuevas:-?}" "${viejas:-?}"
}

echo "── 0. foto de antes"
wp eval '$st=\Akibara\Search\RebuildJob::status(); echo $st ? $st["phase"]." ".$st["done"]."/".$st["total"]." err=".var_export($st["error"],true) : "sin run", PHP_EOL;' || true
echo "   tablas: $(topologia)"

# --------------------------------------------------------------------------
# `_old` NO es un residuo: es la única copia de rollback, y no hay rename
# inverso. `Install::cleanup_old()` sólo comprueba topología y esquema — las
# cinco compuertas de publicación (recibo asentado, huella semántica ackeada,
# generación igual al objetivo y deuda 'clear' comprobada DOS veces) las pone su
# llamador en RebuildJob, y sin estado ninguna de ellas es computable. Aquí se
# para y decide una persona con la foto delante.
# --------------------------------------------------------------------------
if [[ "$(topologia)" == *"old=3"* ]]; then
	cat >&2 <<'PARA'

PARO AQUÍ: hay tablas `_old`.

Eso significa que el RENAME ya ocurrió: las tablas VIVAS son las recién
estrenadas y `_old` es el último índice bueno conocido. No es basura que
recoger, es la vuelta atrás, y no hay rename inverso: deshacerlo cuesta un
rebuild completo.

Este guion NO la toca. Mira la foto y decide:

  wp eval 'echo json_encode(["docs"=>$GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM wp_akb_search_docs"),
    "docs_old"=>$GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM wp_akb_search_docs_old"),
    "gen"=>get_option("akb_search_index_gen"),
    "recibo"=>get_option("akb_search_last_rebuild")], JSON_PRETTY_PRINT), PHP_EOL;'

Si las vivas están completas y el serving responde, la salida es retirar `_old`
por la vía del plugin. Si no, `_old` es lo único servible: no la borres.
PARA
	exit 3
fi

# --------------------------------------------------------------------------
# Desde search 0.1.68 el rescate vive EN EL PLUGIN (`RebuildJob::rescue()`), con
# el orden de los pasos dentro del código para que no se pueda invertir: sombra
# primero, estado después, y el estado NO se retira si la sombra no se soltó
# (S-937170c1, 2026-09-19). Este guion lo llama. Los pasos manuales de abajo
# quedan SÓLO como red para un destino que todavía tenga una versión anterior.
# --------------------------------------------------------------------------
if wp help akb-search rescue >/dev/null 2>&1; then
	echo "── 1-4. wp akb-search rescue (sombra → estado → cola → rebuild)"
	wp akb-search rescue || {
		echo 'El rescate NO procedió: lee el código entre corchetes de arriba.' >&2
		echo '  [hay_old]          hay tablas `_old`: decide una persona (ver el PARO de este guion)' >&2
		echo '  [run_vivo]         hay un rebuild que avanza: espera' >&2
		echo '  [sombra_no_suelta] alguien tiene la cerca de escritura: reintenta' >&2
		exit 4
	}
else
	echo "── (versión anterior del plugin: pasos a mano, en ESTE orden)"
	echo "── 1. suelto la sombra ANTES que nada (si no, el paso 2 acuña el pestillo)"
	wp eval 'echo \Akibara\Search\Install::drop_shadow_tables() ? "sombra fuera (o no la había)" : "NO PUDE soltar la sombra", PHP_EOL;'
	echo "   tablas: $(topologia)"

	if [[ "$(topologia)" != *"new=0"* ]]; then
		echo 'ABORTO: las tablas `_new` siguen ahí. Borrar la option ahora deja el pestillo.' >&2
		echo 'Reintenta: si falla otra vez, alguien tiene el WriteFence tomado.' >&2
		exit 4
	fi

	echo "── 2. ahora sí, retiro el estado del rebuild"
	wp option delete akb_search_rebuild_state || true

	echo "── 3. limpio los pumps encolados (la deuda vive en la tabla de trabajo, no en la cola)"
	wp db query "DELETE FROM wp_actionscheduler_actions WHERE status='pending' AND hook='akb_search_pump_live';" || true

	echo "── 4. arranco un rebuild limpio"
	wp akb-search rebuild || true
fi

echo "── foto de después"
wp eval '$st=\Akibara\Search\RebuildJob::status(); echo $st ? $st["phase"]." ".$st["done"]."/".$st["total"]." err=".var_export($st["error"],true) : "sin run", PHP_EOL; $s=json_decode(get_option("akb_search_editorial_discount_state"),true); $r=get_option("akb_search_editorial_discount_receipt"); echo "seq ", $s["wanted_seq"]??"?", "/", is_array($r)?($r["wanted_seq"]??"?"):"-", " huellas=", var_export(($s["wanted_fingerprint"]??1)===(is_array($r)?($r["fingerprint"]??2):3), true), PHP_EOL;' || true
echo "   tablas: $(topologia)"

cat <<'FIN'

OJO CON EL VENCIMIENTO DE LA PROMO
Si había una acción `akb_search_editorial_discount_boundary` que coincidía con el
estado, un rescate puede dejarla sin coincidir y entonces la promo NO se apaga
sola. Compruébalo:

  wp eval '$s=json_decode(get_option("akb_search_editorial_discount_state"),true);
  global $wpdb; $n=0;
  foreach ($wpdb->get_results("SELECT args FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"akb_search_editorial_discount_boundary\" AND status=\"pending\"") as $r) {
    $a=json_decode($r->args,true);
    if ((int)$a[0]===(int)$s["wanted_seq"] && (string)$a[1]===(string)$s["wanted_fingerprint"] && (int)$a[2]===(int)($s["next_boundary_at"]??0)) $n++;
  }
  echo $n ? "OK: $n accion(es) coinciden" : "NADIE va a apagar la promo", PHP_EOL;'
FIN
