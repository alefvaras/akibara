<?php
/**
 * Script interactivo para el Panel de Reglas Financieras.
 *
 * @package Akibara\Business
 *
 * @var string $ajax_nonce
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
	var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce = '<?php echo esc_js( $ajax_nonce ); ?>';

	// Calculadora de Subsidio en Vivo (Tab 4)
	function recalcSubsidy() {
		var guestDiscInput = document.getElementById('akb_ref_guest_disc');
		var minCartInput = document.getElementById('akb_ref_min_cart');
		var sponsorPtsInput = document.getElementById('akb_ref_sponsor_pts');
		var badge = document.getElementById('akb_subsidy_badge');
		var details = document.getElementById('akb_subsidy_details');

		if (!guestDiscInput || !minCartInput || !sponsorPtsInput || !badge || !details) return;

		var guestDisc = parseInt(guestDiscInput.value, 10) || 2000;
		var minCart = parseInt(minCartInput.value, 10) || 30000;
		var sponsorPts = parseInt(sponsorPtsInput.value, 10) || 1000;

		var baseNetCart = Math.max(0, minCart - guestDisc);
		var cashback = Math.round(baseNetCart * 0.02);
		var welcomeBonus = 500;
		var sponsorCost = sponsorPts;

		var totalSubsidy = guestDisc + welcomeBonus + sponsorCost + cashback;
		var subsidyPct = minCart > 0 ? ((totalSubsidy / minCart) * 100) : 0;
		var ceiling = 15.0;

		var pctStr = subsidyPct.toFixed(2).replace('.', ',') + '%';
		if (subsidyPct <= ceiling) {
			badge.style.background = '#46b450';
			badge.innerText = 'Subsidio Total: ' + pctStr + ' (Bajo el Techo del 15,0%)';
		} else {
			badge.style.background = '#dc3232';
			badge.innerText = 'ALERTA: Subsidio Total ' + pctStr + ' SUPERA EL TECHO (15,0%)';
		}

		details.innerText = 'Suma: $' + guestDisc.toLocaleString('es-CL') + ' (descuento) + $' + welcomeBonus.toLocaleString('es-CL') + ' (bono 1.ª compra) + $' + sponsorCost.toLocaleString('es-CL') + ' (padrino) + $' + cashback.toLocaleString('es-CL') + ' (2% cashback en $' + baseNetCart.toLocaleString('es-CL') + ') = $' + totalSubsidy.toLocaleString('es-CL') + ' sobre $' + minCart.toLocaleString('es-CL') + '.';
	}

	var gDisc = document.getElementById('akb_ref_guest_disc');
	var mCart = document.getElementById('akb_ref_min_cart');
	var sPts = document.getElementById('akb_ref_sponsor_pts');
	if (gDisc) gDisc.addEventListener('input', recalcSubsidy);
	if (mCart) mCart.addEventListener('input', recalcSubsidy);
	if (sPts) sPts.addEventListener('input', recalcSubsidy);

	// Buscador de Series (Tab 2)
	var btnSearch = document.getElementById('akb_btn_search_series');
	var inputSearch = document.getElementById('akb_series_search_input');
	var containerResults = document.getElementById('akb_search_results_container');

	if (btnSearch && inputSearch) {
		btnSearch.addEventListener('click', function() {
			var q = inputSearch.value.trim();
			if (q.length < 2) {
				alert('Escribe al menos 2 caracteres.');
				return;
			}
			containerResults.innerHTML = '<em>Buscando series en catálogo...</em>';
			fetch(ajaxurl + '?action=akb_search_series_for_bundle&_ajax_nonce=' + nonce + '&q=' + encodeURIComponent(q))
				.then(function(res) { return res.json(); })
				.then(function(res) {
					if (!res.success || !res.data || res.data.length === 0) {
						containerResults.innerHTML = '<span style="color:#666;">No se encontraron series con ese nombre.</span>';
						return;
					}
					var html = '<ul style="margin:5px 0; background:#fff; border:1px solid #ccc; padding:10px; border-radius:3px;">';
					res.data.forEach(function(item) {
						var warning = item.is_planeta ? ' <strong style="color:#d63638;">[Editorial Planeta: Margen Estrecho]</strong>' : '';
						var added = item.in_list ? ' <span style="color:#46b450; font-weight:bold;">(Ya en Lista Blanca)</span>' : '';
						var btnAdd = item.in_list ? '' : ' <button type="button" class="button button-small button-primary akb-btn-add-serie" data-id="' + item.term_id + '">+ Agregar al Pack</button>';
						html += '<li style="margin-bottom:6px;"><strong>' + item.name + '</strong> — Editorial: ' + item.editorial + ' (' + item.count + ' tomos | Ref: $' + item.price_ref + ' CLP)' + warning + added + btnAdd + '</li>';
					});
					html += '</ul>';
					containerResults.innerHTML = html;

					document.querySelectorAll('.akb-btn-add-serie').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var termId = this.getAttribute('data-id');
							var profile = document.getElementById('akb_add_profile').value;
							var boModeEl = document.getElementById('akb_add_backorder_mode');
							var boMode = boModeEl ? boModeEl.value : 'inherit';
							var fd = new FormData();
							fd.append('action', 'akb_add_series_to_bundle');
							fd.append('_ajax_nonce', nonce);
							fd.append('term_id', termId);
							fd.append('profile', profile);
							fd.append('backorder_mode', boMode);

							btn.disabled = true;
							btn.innerText = 'Agregando...';

							fetch(ajaxurl, { method: 'POST', body: fd })
								.then(function(r) { return r.json(); })
								.then(function(r) {
									if (r.success) {
										location.reload();
									} else {
										alert(r.data.message || 'Error al agregar');
										btn.disabled = false;
									}
								});
						});
					});
				});
		});
	}

	// Quitar y Toggle de Series (Tab 2)
	document.querySelectorAll('.akb-remove-series').forEach(function(btn) {
		btn.addEventListener('click', function() {
			if (!confirm('¿Seguro que deseas quitar esta serie de Arma tu Pack?')) return;
			var termId = this.getAttribute('data-term-id');
			var fd = new FormData();
			fd.append('action', 'akb_remove_series_from_bundle');
			fd.append('_ajax_nonce', nonce);
			fd.append('term_id', termId);

			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					if (r.success) location.reload();
				});
		});
	});

	document.querySelectorAll('.akb-toggle-series').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var termId = this.getAttribute('data-term-id');
			var fd = new FormData();
			fd.append('action', 'akb_toggle_series_in_bundle');
			fd.append('_ajax_nonce', nonce);
			fd.append('term_id', termId);

			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					if (r.success) location.reload();
				});
		});
	});
});
</script>
