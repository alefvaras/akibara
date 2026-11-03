/* El servidor resuelve el máximo aplicable; esta ayuda sólo presenta el importe. */
(function () {
    'use strict';
    function boot() {
        document.querySelectorAll('[data-akb-redeem]').forEach(function (form) {
            var input = form.querySelector('[name="akb_rw_points"]');
            var preview = form.querySelector('#akb-rw-preview');
            var maximum = form.querySelector('.akb-rw-max');
            var config;
            try { config = JSON.parse(form.dataset.akbRedeem); } catch (e) { return; }
            if (!input || !preview || !maximum || !Number.isSafeInteger(config.value) || config.value <= 0) { return; }
            if (!config.eligible || input.disabled || maximum.disabled) { maximum.hidden = false; return; }
            var pointFormat = new Intl.NumberFormat(document.documentElement.lang || 'es-CL');
            function money(amount) {
                var parts = amount.toFixed(config.decimals).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, config.thousand);
                return config.format.replace('%1$s', config.symbol).replace('%2$s', parts.join(config.decimal));
            }
            function update() {
                var points = Number(input.value);
                if (input.value === '') {
                    preview.textContent = 'Escribe cuántos puntos quieres canjear para ver el descuento aplicable en este carrito.';
                } else if (!Number.isSafeInteger(points) || !Number.isSafeInteger(points * config.value) || points <= 0 || !input.validity.valid) {
                    preview.textContent = 'Ingresa un número entero de puntos entre ' + pointFormat.format(Number(input.min)) + ' y ' + pointFormat.format(Number(input.max)) + '.';
                } else {
                    preview.textContent = pointFormat.format(points) + ' puntos: ' + money(points * config.value) + ' de descuento aplicable en tu carrito actual.';
                }
            }
            maximum.hidden = false;
            maximum.addEventListener('click', function () {
                input.value = input.max;
                update();
                input.focus();
            });
            input.addEventListener('input', update);
            input.addEventListener('change', update);
            update();
        });

        /* Copiado y compartido del enlace de invitación a un amigo */
        if (typeof document.querySelectorAll === 'function') {
            try {
                var copyBtns = document.querySelectorAll('.akb-rw-copy-btn');
                if (copyBtns && copyBtns.forEach) {
                    copyBtns.forEach(function (btn) {
                        if (!btn || typeof btn.getAttribute !== 'function') { return; }
                        var url = btn.getAttribute('data-akb-copy-url');
                        if (!url || typeof btn.addEventListener !== 'function') { return; }
                        var originalHtml = btn.innerHTML;
                        btn.addEventListener('click', function () {
                            function feedback() {
                                btn.innerHTML = '✅ ¡Copiado!';
                                setTimeout(function () { btn.innerHTML = originalHtml; }, 2500);
                            }
                            if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                                navigator.clipboard.writeText(url).then(feedback).catch(function () {
                                    feedback();
                                });
                            } else {
                                feedback();
                            }
                        });
                    });
                }
            } catch (e) {}

            try {
                if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
                    var shareBtns = document.querySelectorAll('.akb-rw-share-btn');
                    if (shareBtns && shareBtns.forEach) {
                        shareBtns.forEach(function (btn) {
                            if (!btn || typeof btn.getAttribute !== 'function' || typeof btn.addEventListener !== 'function') { return; }
                            btn.style.display = 'inline-flex';
                            btn.addEventListener('click', function () {
                                navigator.share({
                                    title: btn.getAttribute('data-akb-share-title') || 'Akibara Manga',
                                    text: btn.getAttribute('data-akb-share-text') || '',
                                    url: btn.getAttribute('data-akb-share-url') || (typeof window !== 'undefined' ? window.location.href : '')
                                }).catch(function () {});
                            });
                        });
                    }
                }
            } catch (e) {}
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());
