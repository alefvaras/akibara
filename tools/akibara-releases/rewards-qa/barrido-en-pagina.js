/**
 * Barrido de controles del panel SIN navegar y SIN contraseña: se ejecuta dentro
 * de una pestaña de wp-admin ya autenticada y habla por `fetch` con el mismo
 * origen, reutilizando la sesión del dueño.
 *
 * Por qué existe: en producción no escribo contraseñas, así que Playwright no
 * puede entrar. Aquí el navegador ya está dentro.
 *
 * Uso (desde la consola de la pestaña de wp-admin):
 *   await akbBarrer('copys', { max: 999 })
 *
 * Devuelve { tab, probados, fallos: [...] }. Restaura cada control a su valor
 * original antes de pasar al siguiente, y no toca `taxonomy_discount`.
 */
window.akbBarrer = async function (tab, opciones) {
  const cfg = Object.assign({ max: 999, soloLeer: false, incluirAcoplados: false }, opciones || {});
  if (tab === 'taxonomy_discount' && !cfg.forzarPeligrosa) {
    return { error: 'taxonomy_discount mueve la foto editorial de precios: no se barre a ciegas.' };
  }
  const URLTAB = `${location.origin}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`;
  const NO_TOCAR = /(_test_|_enviar_|_purgar|_borrar|_reset|_reindex|^confirm_)/i;
  const ACOPLADO = /^[a-z_]+\[\d+\]\[[a-z_]+\]$/i;
  const SECRETO = /(api_key|apikey|token|secret|password|clave)/i;
  const oculta = (n, v) => SECRETO.test(n) ? `«${String(v).slice(0, 4)}…${String(v).length}c»` : `«${v}»`;

  const traer = async () => {
    // Rompe la caché privada de LiteSpeed: en producción sirve páginas de admin
    // viejas durante media hora y haría parecer que los guardados no cuajan
    // (medido el 2026-09-06 con el endpoint del carrito).
    const r = await fetch(`${URLTAB}&_qa=${Date.now()}${Math.random()}`, { credentials: 'include', cache: 'no-store' });
    const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
    const form = [...doc.querySelectorAll('form')].find((f) => f.querySelector('[name="akibara_panel_save"], [name^="akb_"], [name^="akibara_"], [name^="slides"]'));
    return { doc, form };
  };

  const valorDe = (form, name, tipo) => {
    const els = [...form.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
    if (!els.length) return '(ausente)';
    if (tipo === 'checkbox') return String(els[0].checked);
    if (tipo === 'radio') { const m = els.find((e) => e.checked); return m ? String(m.value) : ''; }
    return String(els[0].value ?? '');
  };

  const enviar = async (form, cambios) => {
    const fd = new FormData(form);
    for (const [k, v] of Object.entries(cambios)) {
      if (v === null) fd.delete(k); else fd.set(k, v);
    }
    // SOLO si el formulario es del guardado genérico del panel. Añadirlo a
    // ciegas activa `AdminPanel::handle_save()` en peticiones que van a otro
    // `admin_post_*` (p. ej. `action=akb_editions_save`), y allí
    // `check_admin_referer('akibara_panel_save')` falla contra un nonce que es
    // de otra acción: la petición muere y parece que la pestaña «no guarda».
    // Los 16 fallos de `brand_editions` en producción eran esto (2026-09-06).
    if (form.querySelector('[name="akibara_panel_save"]') && !fd.has('akibara_panel_save')) fd.set('akibara_panel_save', '1');
    const accion = form.getAttribute('action') || URLTAB;
    const r = await fetch(accion, { method: 'POST', credentials: 'include', body: new URLSearchParams([...fd]), redirect: 'follow' });
    return r.status;
  };

  const { form: form0 } = await traer();
  if (!form0) return { error: 'no encontré el formulario del panel' };

  const controles = [...form0.querySelectorAll('input, select, textarea')]
    .map((e) => ({
      name: e.name || '',
      tipo: e.tagName === 'SELECT' ? 'select' : e.tagName === 'TEXTAREA' ? 'textarea' : (e.type || 'text'),
      min: e.getAttribute('min'), max: e.getAttribute('max'), step: e.getAttribute('step'),
      opciones: e.tagName === 'SELECT' ? [...e.options].map((o) => o.value)
        : (e.type === 'radio' ? [...form0.querySelectorAll(`[name="${CSS.escape(e.name)}"]`)].map((r) => r.value) : []),
      deshabilitado: e.disabled, readOnly: !!e.readOnly,
    }))
    .filter((c) => c.name && !/\[\]$/.test(c.name) && !['hidden', 'submit', 'button', 'file', 'search'].includes(c.tipo) && !c.deshabilitado && !c.readOnly)
    .filter((c) => !NO_TOCAR.test(c.name))
    .filter((c) => cfg.incluirAcoplados || !ACOPLADO.test(c.name));

  const unicos = [...new Map(controles.map((c) => [c.name, c])).values()].slice(0, cfg.max);
  if (cfg.soloLeer) return { tab, inventario: unicos.map((c) => `${c.name} [${c.tipo}]`) };

  const proponer = (c, actual) => {
    switch (c.tipo) {
      case 'checkbox': return actual === 'true' ? 'false' : 'true';
      case 'select': case 'radio': { const o = c.opciones.find((x) => x !== actual); return o === undefined ? null : o; }
      case 'number': {
        const paso = Math.abs(Number(c.step)) || 1;
        const min = c.min === null || c.min === '' ? null : Number(c.min);
        const max = c.max === null || c.max === '' ? null : Number(c.max);
        const n = Number(actual); const base = Number.isFinite(n) ? n : (min ?? 0);
        let cand = base + paso;
        if (max !== null && cand > max) cand = base - paso;
        if (min !== null && cand < min) cand = min + paso;
        if ((max !== null && cand > max) || cand === base || !Number.isFinite(cand)) return null;
        return String(Math.round(cand));
      }
      case 'color': return actual.toLowerCase() === '#123456' ? '#654321' : '#123456';
      case 'email': return actual === 'qa@akibara.cl' ? 'qa2@akibara.cl' : 'qa@akibara.cl';
      case 'url': return actual ? actual + '-qa' : 'https://akibara.cl/qa';
      case 'text': case 'textarea': {
        if (/color|colour|_bg$|fondo/i.test(c.name) || /^#[0-9a-f]{3,8}$/i.test(actual)) return actual.toLowerCase() === '#123456' ? '#654321' : '#123456';
        if (/^(https?:\/\/|\/|#)/.test(actual) || /url|enlace|link|href/i.test(c.name)) return actual ? actual + '-qa' : '/qa';
        return actual ? actual + ' QA' : 'QA';
      }
      default: return null;
    }
  };

  const fallos = []; let probados = 0; let saltados = 0;
  for (const c of unicos) {
    try {
      const a = await traer();
      const original = valorDe(a.form, c.name, c.tipo);
      if (original === '(ausente)') { saltados++; continue; }
      const nuevo = proponer(c, original);
      if (nuevo === null || nuevo === original) { saltados++; continue; }

      const cambio = c.tipo === 'checkbox' ? { [c.name]: nuevo === 'true' ? '1' : null } : { [c.name]: nuevo };
      await enviar(a.form, cambio);
      const b = await traer();
      const tras = valorDe(b.form, c.name, c.tipo);

      const vuelta = c.tipo === 'checkbox' ? { [c.name]: original === 'true' ? '1' : null } : { [c.name]: original };
      await enviar(b.form, vuelta);
      const d = await traer();
      const vuelto = valorDe(d.form, c.name, c.tipo);

      probados++;
      if (tras === nuevo && vuelto === original) continue;
      fallos.push({
        name: c.name, tipo: c.tipo,
        que: tras !== nuevo ? `NO guarda: puse ${oculta(c.name, nuevo)} y releí ${oculta(c.name, tras)}`
                            : `NO restaura: era ${oculta(c.name, original)} y quedó ${oculta(c.name, vuelto)}`,
      });
    } catch (e) { fallos.push({ name: c.name, tipo: c.tipo, que: 'error: ' + String(e).slice(0, 90) }); }
  }
  return { tab, probados, saltados, fallos };
};
'akbBarrer listo';
