/** Explicit save feedback and instant tab switching for Rewards settings. */
(function () {
    'use strict';

    // 1. Tab switching
    const tabs = document.querySelectorAll('.akb-rewards-head .nav-tab');
    const panels = document.querySelectorAll('.akb-tab-panel');

    function switchTab(tabId) {
        if (!tabId) return;
        tabs.forEach(tab => {
            const active = tab.getAttribute('data-tab') === tabId;
            tab.classList.toggle('nav-tab-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(panel => {
            const active = panel.getAttribute('id') === 'tab-' + tabId;
            panel.classList.toggle('is-active', active);
        });
        if (history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            history.replaceState(null, '', url);
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const tabId = tab.getAttribute('data-tab');
            switchTab(tabId);
        });
    });

    // Check URL query param on load
    const params = new URLSearchParams(window.location.search);
    const initialTab = params.get('tab') || (window.location.hash ? window.location.hash.replace('#tab-', '').replace('#', '') : '');
    if (initialTab && document.getElementById('tab-' + initialTab)) {
        switchTab(initialTab);
    }

    // 2. Dirty tracking & save feedback
    const form = document.querySelector('[data-akb-rewards-settings]');
    if (!form) return;
    const status = form.querySelector('[data-akb-settings-status]');
    const snapshot = () => JSON.stringify(Array.from(new FormData(form).entries()));
    const saved = snapshot();
    let dirty = false;
    const update = () => {
        dirty = snapshot() !== saved;
        if (status) {
            status.textContent = dirty
                ? 'Tienes cambios sin guardar. Pulsa Guardar ajustes para aplicarlos.'
                : 'Los cambios se aplican al guardar.';
        }
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
