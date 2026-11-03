/** Explicit save feedback for the complete Rewards settings form. */
(function () {
    'use strict';
    const form = document.querySelector('[data-akb-rewards-settings]');
    if (!form) return;
    const status = form.querySelector('[data-akb-settings-status]');
    const snapshot = () => JSON.stringify(Array.from(new FormData(form).entries()));
    const saved = snapshot();
    let dirty = false;
    const update = () => {
        dirty = snapshot() !== saved;
        status.textContent = dirty
            ? 'Tienes cambios sin guardar. Pulsa Guardar ajustes para aplicarlos.'
            : 'Los cambios se aplican al guardar.';
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());
