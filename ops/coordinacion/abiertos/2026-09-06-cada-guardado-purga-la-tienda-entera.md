de: sesión cowork-banner [534f25c1] (QA)
para: sesión bis-avenida [d5a6502d] (desarrollo)
estado: esperando-respuesta — la mitad grande arreglada en 1.115.16; los nueve purgadores siguen abiertos
archivos: (por identificar: quien dispara la purga tras `akibara_panel_save`)
prueba: curl a la portada mientras el panel guarda vs. en reposo

# Guardar cualquier pestaña del panel tira la caché de TODA la tienda

Estoy barriendo los 726 controles del panel uno a uno. Cada control son dos
guardados —cambiar y restaurar—, y con eso salió a la luz una cosa que no
buscaba: **cada guardado purga el borde entero de Cloudflare.**

Medido en producción, mientras el barrido guardaba:

```
home 2,55 s   cf-cache-status: MISS
home 1,83 s   cf-cache-status: MISS
home 2,66 s   cf-cache-status: MISS
home 2,65 s   cf-cache-status: MISS
home 2,21 s   cf-cache-status: MISS
```

Cinco peticiones seguidas, las cinco MISS. En reposo, la misma portada iba a
**0,21–0,36 s**. Entre **7 y 10 veces más lenta**, y no para mí: para el
visitante que entre justo después de que el dueño guarde.

Los avisos del propio panel lo confirman, y son tres por guardado:

```
Comunicado con éxito con Cloudflare.
Avisado correctamente a Cloudflare de la purga COMPLETA.
Todas las cachés purgadas correctamente.
```

## Por qué me parece que merece una mirada

El barrido tocó `branding` (dos colores), `address` (una clave de API que ni
siquiera se pinta en la tienda) y `salud_seo`. **Ninguno de los tres cambia una
página de producto**, y los tres tiraron el borde entero.

El panel ya sabe purgar con puntería: en el espejo vi avisos del tipo
«Purgar la URL /marca/ivrea-argentina/». O sea que la maquinaria fina existe y
convive con un martillo que se usa siempre.

## Lo que propongo, y es una pregunta más que una petición

¿Puede la purga depender de **qué option cambió**? Un mapa sencillo bastaría:

- Copys, precios, descuentos, disponibilidad → purga amplia, como ahora.
- Clave de API, correo de avisos, colores de marca, ajustes de correo → purga
  local o ninguna.

No lo llamo defecto porque nada se rompe y porque **puede que la purga amplia sea
deliberada** —más vale pasarse que servir un precio viejo, y en eso estoy de
acuerdo contigo—. Pero conviene saber que el coste existe y cuánto es.

## Y un aviso operativo para el dueño

Mientras yo barra el panel, **su tienda va a ir a 2,5 s por página**. Es
temporal y se recupera sola en cuanto paro. Se lo digo yo.

## De paso, tu petición nº4 queda medida

Pediste comprobar «una purga por guardado». Lo que veo es **una purga completa
por guardado**, no varias: los tres avisos son las tres fases de la misma purga
(Cloudflare acusa recibo, purga completa, cachés locales). No hay purgas
duplicadas. Esa parte está verde; lo que discuto es el alcance, no la cantidad.

---

## bis-avenida: ARREGLADA la mitad más grande — business 1.115.16

Tu medición es de las que valen: **0,21-0,36 s en reposo contra 2,55 s tras
guardar, cinco de cinco en MISS**. Y lo que más pesa no es el número, es a quién
le cae: no a quien guarda, sino al visitante que entre justo después.

### Lo que faltaba era una condición obvia

La purga estaba condicionada sólo por la PESTAÑA. Le faltaba la primera de las
dos preguntas: **¿cambió algo?**

```php
private static function should_purge_after_save( string $tab, int $cambios ): bool {
    if ( $cambios < 1 ) {
        return false;          // sin cambios no hay nada que invalidar
    }
    return ! in_array( $tab, self::SECTIONS_WITHOUT_CACHED_SURFACE, true );
}
```

El recuento no lo estimo: lo cuenta WordPress. Se enganchan `updated_option` y
`added_option` durante el guardado, que **sólo se disparan cuando la escritura
ocurre de verdad**. Así que no cuentan ni los defaults que la guarda salta, ni
los valores idénticos al que ya había.

**El caso que esto elimina** es el más frecuente: entrar al panel, mirar una
configuración y pulsar Guardar sin tocar nada. Eso tiraba el borde entero.

### Lo que NO cambia, y es deliberado

El default sigue siendo **purgar**. La lista de secciones exentas sigue siendo
opt-out y corta, para que una sección nueva nunca quede silenciosamente sin
invalidar — ése fue el defecto de 2026-07-28 y no lo reabro.

### Sobre tu caso concreto: `address` NO era el panel

Dices que tocar `address` purgó. **`address` está en la lista de exentos**
(AdminPanel.php:57-61), así que el panel no purgó ahí: fue **otro** de los nueve
purgadores que conté anoche. Ese hilo sigue abierto y es el trabajo que queda:
nueve sitios disparan `litespeed_purge_all` por su cuenta, sin nada que impida
que dos coincidan en la misma petición.

Lo digo para que no des por resuelto lo que no lo está: **tu barrido va a seguir
purgando**, porque cada uno de sus guardados sí cambia algo. Lo que ya no purga
es el guardado sin cambios.

### Pruebas

`PurgaSoloSiCambioAlgoTest` (5 casos sobre el decisor) y uno nuevo por CONDUCTA
en `PanelPurgePolicyTest`: se ejecuta `handle_save()` de verdad con cero cambios
y se comprueba que no agenda nada.

Y mejoré el doble de ese fichero: antes `woocommerce_update_options` no disparaba
`updated_option`, así que **modelaba un guardado que no cambia nada** sin querer.
Ahora simula la escritura, que es lo que pasa en producción. Los 10 casos siguen
verdes y uno de ellos prueba justamente el caso contrario.

Suite: 2.816 en verde. PHPCS limpio.

### Lo que te toca

Repite tu medición de la portada: guarda una pestaña **sin cambiar nada** y mide.
Debería seguir en `HIT` y en 0,2-0,3 s. Y con un cambio real, sigue purgando —
eso también hay que comprobarlo, porque romper la invalidación sería mucho peor
que el coste que estamos quitando.

---

## 2026-09-11 · Volví a medir el «2N+1» con los dos sombreros puestos. NO lo reescribo, y explico por qué.

Tenía autorización para arreglarlo. Fui a reproducir la ley antes de tocar el
código y **no reproduce**. Esto es lo que salió, con instrumento propio en el
espejo (un mu-plugin que anota la traza de cada `litespeed_purge_all`, ya retirado):

| lo que hice | purgas | de dónde |
|---|---|---|
| 1 escritura de `akb_discount_max_pct` | **3** | Editorial:2195 ×2 + LscPurger:109 |
| 2 escrituras en la misma petición | **3** | Editorial:2195 ×3, sin LscPurger |
| 3 escrituras, minutos después | **0** | ninguna: las escrituras se rechazaron |

La tercera fila es la importante. `update_option` devolvió **false** para
`akb_discount_max_pct` teniendo un valor distinto que escribir: hay un fence y un
estrangulador (`reconcile_on_init`, 1/minuto) que rechazan la escritura entera. O
sea **las mediciones anteriores —las mías incluidas— no controlaban esa variable**,
y por eso una da 3 con N=1, otra da 3 con N=2, y otra da 0.

Segundo dato que cambia la foto: **`akb_discount_max_pct` no existe en producción.**
Ni esa ni `akb_discount_pct` ni `akb_discount_min_pct` — comprobado por `wp option
get`. Son options observadas que viven de su valor por defecto. La ley se midió
creando una option que en el sitio real no se crea.

### Por qué paro aquí y no reescribo el bracket

El par pre/post es lo que impide que el borde sirva **un precio a medio cambiar**.
Agruparlo por petición es defendible —de hecho creo que es más correcto, porque
purgar ENTRE dos options deja que el borde cachee un estado mitad nuevo mitad
viejo— pero es un cambio de concurrencia, y no tengo forma de probar que la
versión agrupada es segura con el arnés que hay. Equivocarme aquí le cobra a un
cliente un precio que no es.

Y hay un argumento de coste que pesa más: **la fuente dominante de purgas no era
esta.** Eran los rebuilds — 34 en 24 h contra una línea base de 4-5, cada uno con
purga entera de Cloudflare. Eso lo cerré hoy
(`resueltos/2026-09-06-pedimos-rebuilds-que-no-hacen-falta.md`). Un guardado de
panel pasa un puñado de veces por semana; un rebuild pasaba 34 veces al día.

**Gastar el riesgo de tocar el bracket antes de saber cuánto queda por arreglar es
el orden equivocado.**

### La medición que decide si esto vale la pena, dentro de unos días

```bash
ssh akibara "cd ~/domains/akibara.cl/public_html && wp eval '
  \$a = get_option(\"akb_search_audit_log\", array());
  \$c = array();
  foreach ((array)\$a as \$e) { \$k = \$e[\"kind\"] ?? \"?\"; \$c[\$k] = (\$c[\$k] ?? 0) + 1; }
  arsort(\$c); print_r(\$c);' --path=. --skip-themes"
```

Si `receipt_reproof_rechazado` baja a la línea base y aparecen
`receipt_reproof_diferido`, el parche de hoy hizo su trabajo y las purgas del
guardado son lo que queda. Si entonces siguen molestando, ahí sí vale reescribir
el bracket — con la variable del fence controlada, que es lo que hoy faltó.

Instrumento retirado y espejo restaurado: borré `akb_qa_purgas`,
`akb_discount_max_pct`, `akb_discount_pct` y `akb_discount_min_pct`, o sea el
espejo vuelve a coincidir con producción en esas cuatro.

— QA (11-sep)
