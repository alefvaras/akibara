(function () {
	'use strict';

	function initializeFilters() {
		document.querySelectorAll('.akb-tax-filter-bar').forEach(function (filterBar) {
			const accordion = filterBar.closest('details.akb-accordion');
			if (!accordion) {
				return;
			}

			const search = filterBar.querySelector('.akb-filter-search');
			const toggle = filterBar.querySelector('.akb-filter-toggle');
			const counter = filterBar.querySelector('.akb-filter-counter');
			const rows = accordion.querySelectorAll('tr.akb-taxonomy-discount-row');

			function applyFilter() {
				const query = (search?.value || '').toLowerCase().trim();
				const discountedOnly = Boolean(toggle?.checked);
				let visible = 0;

				rows.forEach(function (row) {
					const label = row.querySelector('label');
					const input = row.querySelector('input[type="number"]');
					const labelText = (label?.textContent || '').toLowerCase();
					const percentage = Number.parseInt(input?.value || '0', 10) || 0;
					const show = (!query || labelText.includes(query)) && (!discountedOnly || percentage > 0);

					row.hidden = !show;
					if (show) {
						visible += 1;
					}
				});

				if (counter) {
					counter.textContent = visible + ' visibles de ' + rows.length;
				}
			}

			search?.addEventListener('input', applyFilter);
			toggle?.addEventListener('change', applyFilter);
			applyFilter();
		});
	}

	function initializeRuleLinks() {
		document.querySelectorAll('a[href^="#akb-accordion-"]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				const target = document.querySelector(link.getAttribute('href'));
				if (!target) {
					return;
				}

				event.preventDefault();
				target.open = true;
				target.scrollIntoView({
					behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
					block: 'start'
				});
			});
		});
	}

	function initializeDirtyState() {
		const form = document.querySelector('.akb-panel-form');
		if (!form) {
			return;
		}

		const inputs = Array.from(form.querySelectorAll('input[name], select[name], textarea[name]'));
		if (!inputs.length) {
			return;
		}

		inputs.forEach(function (input) {
			input.dataset.akbOriginal = input.type === 'checkbox' || input.type === 'radio'
				? (input.checked ? '1' : '0')
				: (input.value || '');
		});

		const saveBar = document.createElement('div');
		saveBar.className = 'akb-sticky-save-bar';
		saveBar.id = 'akb-sticky-save-bar';
		saveBar.setAttribute('role', 'status');
		saveBar.setAttribute('aria-live', 'polite');
		saveBar.innerHTML = '<strong>Tienes cambios sin guardar.</strong>' +
			'<button type="submit" name="akibara_panel_save" class="button button-primary akb-sticky-save-bar__button">' +
			'Guardar ajustes</button>';
		form.appendChild(saveBar);

		function currentValue(input) {
			return input.type === 'checkbox' || input.type === 'radio'
				? (input.checked ? '1' : '0')
				: (input.value || '');
		}

		function isDirty() {
			return inputs.some(function (input) {
				return currentValue(input) !== input.dataset.akbOriginal;
			});
		}

		function refreshDirtyState() {
			saveBar.classList.toggle('is-active', isDirty());
			document.querySelectorAll('tr.akb-taxonomy-discount-row input[type="number"]').forEach(function (input) {
				const percentage = Number.parseInt(input.value, 10) || 0;
				input.classList.toggle('akb-pct-active', percentage > 0);
			});
		}

		inputs.forEach(function (input) {
			input.addEventListener('input', refreshDirtyState);
			input.addEventListener('change', refreshDirtyState);
		});
		refreshDirtyState();

		function warnBeforeUnload(event) {
			if (!isDirty()) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		}

		window.addEventListener('beforeunload', warnBeforeUnload);
		form.addEventListener('submit', function () {
			window.removeEventListener('beforeunload', warnBeforeUnload);
		});
	}

	function initializeDateValidation() {
		const fromInput = document.getElementById('akibara_taxonomy_discount_from');
		const toInput = document.getElementById('akibara_taxonomy_discount_to');
		if (!fromInput || !toInput) {
			return;
		}

		function validateDates() {
			const from = fromInput.value;
			const to = toInput.value;
			toInput.setCustomValidity(
				from && to && from > to
					? 'La fecha "hasta" debe ser igual o posterior a "desde".'
					: ''
			);
		}

		fromInput.addEventListener('change', validateDates);
		toInput.addEventListener('change', validateDates);
		validateDates();
	}

	initializeFilters();
	initializeRuleLinks();
	initializeDirtyState();
	initializeDateValidation();
})();
